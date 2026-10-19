<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Editor;

/**
 * Hosts whose iframes the embed block may reference.
 *
 * One list, read at both ends of the pipeline: EditorJsSanitizer drops an
 * embed outside it on save, and BlockRenderer checks again before emitting
 * the iframe, so a document that reached the database some other way still
 * cannot frame an arbitrary origin (clickjacking / XSS through the embed
 * block).
 */
final class EmbedHosts
{
    /** @var list<string> */
    private const HOSTS = [
        'youtube.com', 'youtube-nocookie.com', 'youtu.be',
        'vimeo.com', 'player.vimeo.com',
        'instagram.com', 'twitter.com', 'x.com', 'platform.twitter.com',
        'facebook.com', 'codepen.io',
        'open.spotify.com', 'soundcloud.com', 'w.soundcloud.com',
        'tiktok.com', 'twitch.tv', 'player.twitch.tv',
        'pinterest.com', 'coub.com',
    ];

    /** Whether the URL's host is an allowed host or a subdomain of one. */
    public static function allows(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
