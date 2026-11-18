<?php

namespace TriggerEngage\Server\Mcp\Tools\Analytics;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Services\Analytics;

class GetAnalytics extends Tool
{
    public function __construct(protected Analytics $analytics) {}

    public function name(): string
    {
        return 'get_analytics';
    }

    public function title(): string
    {
        return 'Workspace analytics';
    }

    public function description(): string
    {
        return 'The Analytics page\'s numbers for the last 7, 14, 30 or 90 days: totals of messages, deliveries, '
            .'failures (failed or bounced), automation runs and events, each against the preceding window of the same '
            .'length (delta in percent); the sent → delivered → opened → clicked funnel; a per-channel split; and, on '
            .'request, the daily series. Dates in the series are server dates.';
    }

    public function properties(): array
    {
        return [
            'days' => ['type' => 'integer', 'enum' => Analytics::RANGES, 'description' => 'Window length (default 30).'],
            'include_series' => ['type' => 'boolean', 'description' => 'Include the daily series (default false).'],
        ];
    }

    public function rules(): array
    {
        return [
            'days' => ['nullable', 'integer', 'in:'.implode(',', Analytics::RANGES)],
            'include_series' => ['nullable', 'boolean'],
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $report = $this->analytics->report($context->workspace, (int) ($arguments['days'] ?? 30));

        if (! ($arguments['include_series'] ?? false)) {
            unset($report['series']);
        }

        return [...$report, 'url' => $context->url('analytics.index', ['days' => $report['days']])];
    }
}
