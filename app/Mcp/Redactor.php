<?php

namespace TriggerEngage\Server\Mcp;

/**
 * What an agent may read about the people in a workspace.
 *
 * Contact details (email addresses and phone numbers) are masked unless the
 * host turns `trigger-engage-server.mcp.reveal_contact_details` on: enough
 * survives to tell two people apart and to see that an address exists, not
 * enough to contact anyone. Credentials are never shown, whatever the
 * setting: push tokens, keys and passwords become a "[redacted]" marker, and
 * the signature on signed links (unsubscribe, preference centre) is
 * stripped, because a signed link acts for the person it was made for.
 *
 * Masking applies to what tools return, not to what they accept: an agent
 * can still look a person up by their full email address.
 */
class Redactor
{
    public const REDACTED = '[redacted]';

    /** Keys that hold a credential, matched as whole words or word parts. */
    protected const SECRET_KEY = '/(token|secret|password|passwd|api[_-]?key|authorization|identity[_-]?hash)/i';

    /**
     * Keys that hold a phone number. `mobile` alone is too broad (a profile
     * may carry `mobile_app_seen_at`), so only the number-shaped spellings count.
     */
    protected const PHONE_KEY = '/phone|msisdn|whatsapp|^mobile$|mobile[_\-.]?(number|no)\b|^tel$/i';

    protected const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    protected const EXPO_TOKEN = '/Expo(nent)?PushToken\[[^\]]+\]/';

    /** `;` catches the HTML-escaped `&amp;signature=` inside a rendered email. */
    protected const SIGNATURE = '/([?&;]signature=)[0-9a-f]+/i';

    public function __construct(protected bool $revealContacts = false) {}

    public function revealsContacts(): bool
    {
        return $this->revealContacts;
    }

    public function email(?string $email): ?string
    {
        if ($email === null || $email === '' || $this->revealContacts) {
            return $email;
        }

        if (! str_contains($email, '@')) {
            return $this->mask($email, 1);
        }

        [$local, $domain] = explode('@', $email, 2);
        $labels = explode('.', $domain);
        $tld = count($labels) > 1 ? array_pop($labels) : null;

        return mb_substr($local, 0, 1).'***@'.mb_substr(implode('.', $labels), 0, 1).'***'.($tld !== null ? '.'.$tld : '');
    }

    public function phone(?string $phone): ?string
    {
        if ($phone === null || $phone === '' || $this->revealContacts) {
            return $phone;
        }

        $prefix = str_starts_with($phone, '+') ? mb_substr($phone, 0, 4) : '';
        $rest = mb_substr($phone, mb_strlen($prefix));
        $keep = min(3, max(0, mb_strlen($rest) - 4));

        return $prefix.str_repeat('*', max(0, mb_strlen($rest) - $keep)).mb_substr($rest, mb_strlen($rest) - $keep);
    }

    /**
     * A message's destination, masked the way its channel's address is.
     * Push destinations are tokens or the person's external id, so a token
     * is redacted and an id is left alone.
     */
    public function address(string $channel, ?string $address): ?string
    {
        return match ($channel) {
            'email' => $this->email($address),
            'sms' => $this->phone($address),
            default => $address === null ? null : (preg_replace(self::EXPO_TOKEN, 'ExponentPushToken['.self::REDACTED.']', $address) ?? $address),
        };
    }

    /**
     * Profile attributes, event payloads, run context and step output: walk
     * the structure and mask by key, then by value.
     */
    public function data(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SECRET_KEY, $key) === 1) {
            if (is_array($value)) {
                return $value === [] ? [] : '['.count($value).' '.(count($value) === 1 ? 'value' : 'values').' redacted]';
            }

            return $value === null || $value === '' ? $value : self::REDACTED;
        }

        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $childKey => $child) {
                $redacted[$childKey] = $this->data($child, is_string($childKey) ? $childKey : $key);
            }

            return $redacted;
        }

        if (! is_string($value)) {
            return $value;
        }

        if ($key !== null && preg_match(self::PHONE_KEY, $key) === 1 && preg_match_all('/\d/', $value) >= 7) {
            return $this->phone($value);
        }

        return $this->text($value);
    }

    /**
     * Configuration (channel settings): credentials go, everything else
     * stays as written, because a sender address is not a person's.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function withoutSecrets(array $settings): array
    {
        return collect($settings)
            ->reject(fn ($value, $key) => is_string($key) && preg_match(self::SECRET_KEY, $key) === 1)
            ->map(fn ($value) => is_array($value) ? $this->withoutSecrets($value) : $value)
            ->all();
    }

    /** Free text: message bodies, subjects, provider errors. */
    public function text(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $text = preg_replace(self::SIGNATURE, '$1'.self::REDACTED, $text) ?? $text;
        $text = preg_replace(self::EXPO_TOKEN, 'ExponentPushToken['.self::REDACTED.']', $text) ?? $text;

        if ($this->revealContacts) {
            return $text;
        }

        return preg_replace_callback(self::EMAIL, fn (array $match) => (string) $this->email($match[0]), $text) ?? $text;
    }

    protected function mask(string $value, int $keep): string
    {
        return mb_substr($value, 0, $keep).str_repeat('*', max(3, mb_strlen($value) - $keep));
    }
}
