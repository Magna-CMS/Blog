<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Frontend;

use Illuminate\Support\Facades\Log;
use Magna\Frontend\FrontendPage;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\PostPermalink;
use Throwable;

/**
 * The public page a single post is served at, mounted by Magna Pages inside
 * the site's layout shell. Without Pages the definition is inert data and
 * the blog stays headless.
 *
 * Its path IS the permalink pattern (`/blog/{slug}` mounts `blog/{slug}`),
 * so the links the blog emits and the page that answers them cannot
 * disagree. Built on every request the plugin's contracts are wired, which
 * is also why a pattern edit takes effect on the next request.
 */
final class PostFrontendPage
{
    public static function make(): FrontendPage
    {
        return new FrontendPage(
            path: self::path(),
            name: 'blog.post',
            title: 'Blog post',
            view: 'blog::frontend.post',
            menuVisible: false,
            controller: PostPageProvider::class,
        );
    }

    /**
     * The configured pattern as a route, or the default one. A pattern the
     * router cannot serve (no {slug}, a token sharing a segment, …) is
     * reported rather than mounted: PostPermalink::fromSettings() falls back
     * the same way, so links keep pointing at the page that is served.
     */
    private static function path(): string
    {
        $default = (string) (new PostPermalink(PostPermalink::DEFAULT_PATTERN))->routePath();

        try {
            $pattern = BlogSettings::get()->permalink_pattern;
        } catch (Throwable) {
            // Contracts are wired at boot; a settings store that cannot be
            // read yet (pre-install, unreachable database) must not take
            // the boot down with it.
            return $default;
        }

        $route = (new PostPermalink($pattern))->routePath();
        if ($route !== null) {
            return $route;
        }

        Log::warning('Blog permalink pattern cannot be served as a page; using the default.', [
            'pattern' => $pattern,
            'default' => PostPermalink::DEFAULT_PATTERN,
        ]);

        return $default;
    }
}
