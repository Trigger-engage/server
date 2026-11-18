<?php

namespace TriggerEngage\Server\Mcp\Support;

use Illuminate\Database\Eloquent\Model;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\ToolError;

/**
 * The second step of a live action. A preview returns a confirmation; only a
 * call that brings it back executes. The confirmation is signed with the
 * app key over who asked, which action, on which record, and a fingerprint
 * of that record's state, so it stops working when it expires, when anyone
 * else uses it, when it is used for a different record or action, and when
 * the record changes between preview and confirm (the copy was edited, the
 * audience grew). Executing changes the state too, so it can't be replayed.
 * Nothing is stored.
 */
class Confirmation
{
    public const TTL_MINUTES = 10;

    /** @return array{confirmation: string, expires_at: string} */
    public static function issue(Context $context, string $action, Model $subject, string $fingerprint): array
    {
        $expires = now()->addMinutes(self::TTL_MINUTES)->getTimestamp();

        return [
            'confirmation' => $expires.'.'.self::signature($context, $action, $subject, $fingerprint, $expires),
            'expires_at' => (string) $context->time(now()->setTimestamp($expires)),
        ];
    }

    public static function check(Context $context, string $action, Model $subject, string $fingerprint, string $confirmation): void
    {
        [$expires, $signature] = array_pad(explode('.', $confirmation, 2), 2, '');

        if (! ctype_digit($expires) || $signature === '') {
            throw new ToolError('That confirmation is not one this server issued. Call again without confirmation for a fresh preview.');
        }

        if ((int) $expires < now()->getTimestamp()) {
            throw new ToolError('That confirmation expired (they last '.self::TTL_MINUTES.' minutes). Call again without confirmation for a fresh preview, and show it to the person again.');
        }

        if (! hash_equals(self::signature($context, $action, $subject, $fingerprint, (int) $expires), $signature)) {
            throw new ToolError('That confirmation does not match what is there now: it was changed since the preview, it was already done, '
                .'or the confirmation is for something else. Call again without confirmation for a fresh preview, and show it to the person again.');
        }
    }

    protected static function signature(Context $context, string $action, Model $subject, string $fingerprint, int $expires): string
    {
        $payload = implode('|', [
            $context->workspace->id,
            $context->actor,
            $action,
            $subject->getMorphClass(),
            $subject->getKey(),
            $fingerprint,
            $expires,
        ]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
