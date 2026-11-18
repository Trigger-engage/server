<?php

namespace TriggerEngage\Server\Mcp\Support;

/**
 * Rendered emails are whole HTML documents with inlined styles. An agent
 * reading what a message said wants the words and the links, at a fraction
 * of the size.
 */
class HtmlText
{
    public const MAX_LENGTH = 20000;

    public static function from(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $text = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = preg_replace_callback('#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function (array $match): string {
            $label = trim(strip_tags($match[3]));
            $href = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);

            return $label === '' || $label === $href ? " {$href} " : "{$label} ({$href})";
        }, $text) ?? $text;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|h[1-6]|li|tr|table|blockquote)>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /**
     * Cap a body so one message cannot fill the model's context.
     *
     * @return array{0: string, 1: bool} the text and whether it was cut
     */
    public static function cap(string $text, int $max = self::MAX_LENGTH): array
    {
        return mb_strlen($text) > $max ? [mb_substr($text, 0, $max), true] : [$text, false];
    }
}
