<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;

/**
 * Builds a post's public path from the configured permalink pattern.
 *
 * The pattern (BlogSettings::$permalink_pattern) is the ONE authoritative
 * description of a post's URL: the links the blog emits (Loop source,
 * related posts, prev/next, sitemap) and the path the public post page is
 * mounted at (PostFrontendPage) are both read from it here, so what the site
 * links to is what it serves.
 *
 * Supported tokens are the ones the settings screen advertises: {slug},
 * {year}, {month}, {day}, {category}. Anything else in the pattern is
 * dropped rather than emitted — a literal "{author}" in an href is a broken
 * link, and a silently shorter path is the better failure.
 */
final class PostPermalink
{
    public const DEFAULT_PATTERN = '/blog/{slug}';

    private const TOKENS = ['slug', 'year', 'month', 'day', 'category'];

    public function __construct(private readonly string $pattern) {}

    /**
     * The configured pattern, or the default when the post page could not be
     * served at it (see routePath()) — links and the mounted page then
     * still agree, instead of every link pointing at a 404.
     */
    public static function fromSettings(): self
    {
        return self::forPattern(BlogSettings::get()->permalink_pattern);
    }

    /**
     * $pattern, or the default when the post page could not be served at it
     * — the same fallback fromSettings() applies, for a pattern that is not
     * (or no longer) the configured one, such as the one a settings save is
     * about to replace.
     */
    public static function forPattern(string $pattern): self
    {
        $configured = new self($pattern);

        return $configured->routePath() !== null ? $configured : new self(self::DEFAULT_PATTERN);
    }

    /** The pattern this instance builds paths from. */
    public function pattern(): string
    {
        return $this->pattern;
    }

    /**
     * The pattern as a frontend-page path (`blog/{slug}`), or null when the
     * page router cannot serve it: no {slug}, an unknown or repeated token,
     * an empty segment, or a token sharing a segment with text
     * (`{year}-{slug}`) — the router captures whole segments only.
     */
    public function routePath(): ?string
    {
        $segments = explode('/', trim($this->pattern, '/'));
        $tokens = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                return null;
            }
            if (! str_contains($segment, '{') && ! str_contains($segment, '}')) {
                continue;
            }
            if (preg_match('/^\{([a-z]+)\}$/', $segment, $match) !== 1
                || ! in_array($match[1], self::TOKENS, true)
                || in_array($match[1], $tokens, true)) {
                return null;
            }
            $tokens[] = $match[1];
        }

        return in_array('slug', $tokens, true) ? implode('/', $segments) : null;
    }

    /** The literal start of the pattern, up to its first token (`/blog/`). */
    public function staticPrefix(): string
    {
        $prefix = strstr($this->pattern, '{', true);

        return '/'.ltrim($prefix === false ? $this->pattern : $prefix, '/');
    }

    /**
     * The path a request for these route parameters asked for, in the same
     * shape for() produces — so the post page can refuse any path that is
     * not the post's canonical one.
     *
     * @param  array<string, mixed>  $params
     */
    public function pathFromParams(array $params): string
    {
        $replacements = [];
        foreach (self::TOKENS as $token) {
            $value = $params[$token] ?? '';
            // normalise() strips brace runs, so `{x}2026` would otherwise
            // collapse onto `2026` and serve an alias of the canonical URL.
            if (! is_string($value) || strpbrk($value, '{}/') !== false) {
                return '';
            }
            $replacements['{'.$token.'}'] = $value;
        }

        return $this->normalise(strtr($this->pattern, $replacements));
    }

    /**
     * Whether resolving this pattern needs the category relation loaded —
     * so a caller listing many posts can eager-load it only when it will
     * actually be read, instead of on every query.
     */
    public function usesCategory(): bool
    {
        return str_contains($this->pattern, '{category}');
    }

    public function for(Post $post): string
    {
        /*
         * scopeLive() admits posts with a null published_at (published, no
         * date recorded), so the date tokens fall back to created_at rather
         * than resolving to an empty segment.
         */
        $date = $post->published_at ?? $post->created_at;

        $path = strtr($this->pattern, [
            '{slug}' => (string) $post->slug,
            '{year}' => $date?->format('Y') ?? '',
            '{month}' => $date?->format('m') ?? '',
            '{day}' => $date?->format('d') ?? '',
            /*
             * Only read the relation when the pattern will use it. strtr
             * ignores a value whose token is absent, but PHP builds this
             * array first — so an unguarded read is a query per post on a
             * pattern that never wanted a category, and a hard failure
             * under Model::preventLazyLoading().
             */
            '{category}' => $this->usesCategory() ? $this->categorySlug($post) : '',
        ]);

        return $this->normalise($path);
    }

    /**
     * The post's public URL: its path under the pattern, prefixed with the
     * locale it is served in (/fr/blog/bonjour). What every link the blog
     * emits uses; for() stays the unprefixed path the post page's route
     * captures.
     */
    public function url(Post $post): string
    {
        return app(PostLocales::class)->urlFor($post->locale, $this->for($post));
    }

    private function normalise(string $path): string
    {
        // Unknown tokens, and known ones that resolved to nothing, leave
        // empty segments behind — collapse them so the path stays walkable.
        $path = (string) preg_replace('/\{[^}]*\}/', '', $path);
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/');

        return $path === '' || $path[0] !== '/' ? '/'.ltrim($path, '/') : $path;
    }

    /**
     * The post's category slug, or an empty string.
     *
     * Read through getRelationValue() rather than `$post->category?->slug`
     * because the relation's declared type is non-nullable while
     * blog_posts.category_id is a nullable column — the property access
     * reads as never-null to static analysis, and a post whose category was
     * deleted would then be a fatal instead of a shorter path. The
     * instanceof check is the honest form of the same guard.
     */
    private function categorySlug(Post $post): string
    {
        $category = $post->getRelationValue('category');

        return $category instanceof Category ? (string) $category->slug : '';
    }
}
