<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Editor;

/**
 * The URL rule for every link and media field in a block document, applied
 * by EditorJsSanitizer on save and again by BlockRenderer on output — one
 * rule, so the two ends cannot drift apart.
 */
final class LinkUrl
{
    /**
     * The URL when it is a same-origin root-relative path (/storage/…) or
     * http(s); '' otherwise. Protocol-relative URLs are refused, including
     * `/\host`, which browsers read the same way as `//host`.
     */
    public static function clean(mixed $value): string
    {
        $url = is_string($value) ? trim($value) : '';

        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_starts_with($url, '/\\')) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }
}
