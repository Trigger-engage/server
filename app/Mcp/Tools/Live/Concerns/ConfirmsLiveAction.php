<?php

namespace TriggerEngage\Server\Mcp\Tools\Live\Concerns;

use Illuminate\Database\Eloquent\Model;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\Audit;
use TriggerEngage\Server\Mcp\Support\Confirmation;

/**
 * A live tool in two calls. Without `confirmation` it changes nothing and
 * returns what would happen plus a confirmation; with the confirmation it
 * does it, provided nothing changed in between.
 *
 * Each tool supplies the record, the guard, the fingerprint of the state the
 * preview describes, the preview, and the action.
 */
trait ConfirmsLiveAction
{
    public function writes(): bool
    {
        return true;
    }

    public function live(): bool
    {
        return true;
    }

    abstract protected function subject(array $arguments, Context $context): Model;

    /** Throw a ToolError when the action can't happen at all. */
    abstract protected function guard(Model $subject, Context $context): void;

    /** Everything the person approves in the preview, so a change to any of it voids the confirmation. */
    abstract protected function fingerprint(Model $subject): string;

    /** @return array<string, mixed> */
    abstract protected function preview(Model $subject, Context $context): array;

    /**
     * Do it, and say what was done.
     *
     * @return array{summary: string, result: array<string, mixed>}
     */
    abstract protected function perform(Model $subject, Context $context): array;

    /** @return array<string, array<string, mixed>> */
    protected function confirmationProperty(): array
    {
        return ['confirmation' => [
            'type' => 'string',
            'description' => 'Leave out to get a preview and a confirmation. After the person has read the preview and said yes, '
                .'call again with the same arguments plus this confirmation to do it.',
        ]];
    }

    public function handle(array $arguments, Context $context): array
    {
        $subject = $this->subject($arguments, $context);
        $this->guard($subject, $context);
        $fingerprint = $this->fingerprint($subject);

        if (blank($arguments['confirmation'] ?? null)) {
            return [
                'done' => false,
                'preview' => $this->preview($subject, $context),
                ...Confirmation::issue($context, $this->name(), $subject, $fingerprint),
                'next' => 'Nothing has happened yet. Show this preview to the person. Only if they say yes, call '
                    .$this->name().' again with the same arguments and this confirmation.',
            ];
        }

        Confirmation::check($context, $this->name(), $subject, $fingerprint, (string) $arguments['confirmation']);
        ['summary' => $summary, 'result' => $result] = $this->perform($subject, $context);
        Audit::record($context, $this->name(), $subject, $summary, $result);

        return ['done' => true, 'what_happened' => $summary, ...$result];
    }
}
