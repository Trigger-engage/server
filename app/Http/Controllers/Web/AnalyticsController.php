<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Message;

class AnalyticsController extends Controller
{
    protected const RANGES = [7, 14, 30, 90];

    public function __invoke(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $days = in_array($request->integer('days'), self::RANGES, true) ? $request->integer('days') : 30;

        $messages = fn () => Message::query()->where('workspace_id', $workspace->id);
        $runs = fn () => AutomationRun::query()->where('workspace_id', $workspace->id);
        $events = fn () => EventOccurrence::query()->where('workspace_id', $workspace->id);

        return Inertia::render('Analytics', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'days' => $days,
            'ranges' => self::RANGES,
            'series' => [
                'messages' => $this->dailySeries($messages(), $days),
                'delivered' => $this->dailySeries($messages()->where('status', 'delivered'), $days),
                'failed' => $this->dailySeries($messages()->whereIn('status', ['failed', 'bounced']), $days),
                'runs' => $this->dailySeries($runs(), $days),
                'events' => $this->dailySeries($events(), $days),
            ],
            'totals' => [
                'messages' => $this->totalWithDelta($messages(), $days),
                'delivered' => $this->totalWithDelta($messages()->where('status', 'delivered'), $days),
                'failed' => $this->totalWithDelta($messages()->whereIn('status', ['failed', 'bounced']), $days),
                'runs' => $this->totalWithDelta($runs(), $days),
                'events' => $this->totalWithDelta($events(), $days),
            ],
            'funnel' => $this->deliveryFunnel($messages(), $days),
            'channels' => $this->channelBreakdown($messages(), $days),
        ]);
    }

    /**
     * A continuous, zero-filled daily count so the chart has a point for every
     * day in the window even when nothing happened.
     *
     * @return array<int, array{date: string, value: int}>
     */
    protected function dailySeries(Builder $query, int $days): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $expr = $this->dayExpression();

        $counts = $query->where('created_at', '>=', $since)
            ->selectRaw("$expr as day, count(*) as total")
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(0, $days - 1))
            ->map(function (int $offset) use ($days, $counts): array {
                $date = now()->subDays($days - 1 - $offset)->format('Y-m-d');

                return ['date' => $date, 'value' => (int) ($counts[$date] ?? 0)];
            })
            ->all();
    }

    /** @return array{current: int, previous: int, delta: int} */
    protected function totalWithDelta(Builder $query, int $days): array
    {
        $current = (clone $query)->where('created_at', '>=', now()->subDays($days))->count();
        $previous = (clone $query)
            ->whereBetween('created_at', [now()->subDays($days * 2), now()->subDays($days)])
            ->count();

        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => $previous > 0 ? (int) round((($current - $previous) / $previous) * 100) : ($current > 0 ? 100 : 0),
        ];
    }

    /** @return array<int, array{stage: string, value: int, rate: float}> */
    protected function deliveryFunnel(Builder $query, int $days): array
    {
        $base = $query->where('created_at', '>=', now()->subDays($days));

        $sent = (clone $base)->count();
        $stages = [
            'Sent' => $sent,
            'Delivered' => (clone $base)->whereNotNull('delivered_at')->count(),
            'Opened' => (clone $base)->whereNotNull('opened_at')->count(),
            'Clicked' => (clone $base)->whereNotNull('clicked_at')->count(),
        ];

        return collect($stages)->map(fn (int $value, string $stage) => [
            'stage' => $stage,
            'value' => $value,
            'rate' => $sent > 0 ? round($value / $sent * 100, 1) : 0.0,
        ])->values()->all();
    }

    /** @return array<int, array{channel: string, total: int, delivered: int, failed: int}> */
    protected function channelBreakdown(Builder $query, int $days): array
    {
        $rows = $query->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('channel, status, count(*) as total')
            ->groupBy('channel', 'status')
            ->get();

        return $rows->groupBy('channel')->map(function ($group, $channel): array {
            return [
                'channel' => $channel,
                'total' => (int) $group->sum('total'),
                'delivered' => (int) $group->where('status', 'delivered')->sum('total'),
                'failed' => (int) $group->whereIn('status', ['failed', 'bounced'])->sum('total'),
            ];
        })->values()->all();
    }

    /** Portable "truncate timestamp to day" expression for the active driver. */
    protected function dayExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => 'DATE(created_at)',
            'pgsql' => "to_char(created_at, 'YYYY-MM-DD')",
            default => "strftime('%Y-%m-%d', created_at)",
        };
    }
}
