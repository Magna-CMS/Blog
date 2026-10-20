<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

/**
 * A summary for a post nobody wrote one for: the first paragraph of its
 * body, as plain text, at most MAX_LENGTH characters and cut between words.
 *
 * Only `paragraph` blocks count. A heading is a title, not a summary, and a
 * quote or a code sample says something, but rarely what the post is about.
 */
final class ExcerptGenerator
{
    public const MAX_LENGTH = 160;

    private const ELLIPSIS = '…';

    /** @param  mixed  $content  The post's Editor.js document. */
    public static function fromContent(mixed $content): ?string
    {
        $blocks = is_array($content) && is_array($content['blocks'] ?? null) ? $content['blocks'] : [];

        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'paragraph') {
                continue;
            }

            $text = is_array($block['data'] ?? null) ? ($block['data']['text'] ?? null) : null;
            $plain = is_string($text) ? self::plain($text) : '';

            if ($plain !== '') {
                return self::shorten($plain);
            }
        }

        return null;
    }

    /** Markup out, entities decoded, every run of whitespace one space. */
    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function shorten(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        // Room for the ellipsis, then back to the last whole word.
        $cut = mb_substr($text, 0, self::MAX_LENGTH - mb_strlen(self::ELLIPSIS) + 1);
        $space = mb_strrpos($cut, ' ');
        $cut = $space !== false && $space > 0 ? mb_substr($cut, 0, $space) : mb_substr($cut, 0, -1);

        return rtrim($cut, " \t\n\r\0\x0B,;:.-").self::ELLIPSIS;
    }
}
