<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;

/**
 * Builds a post's public path from the configured permalink pattern.
 *
 * The pattern (BlogSettings::$permalink_pattern) was stored as "a hint for
 * future frontend plugins" and never had an implementation, because the blog
 * is headless — it serves JSON and mounts no public routes. The Loop data
 * source is the first consumer that must turn a post into a link, so the
 * pattern gets ONE authoritative reader here rather than an inline
 * str_replace at each future call site.
 *
 * Supported tokens are the ones the settings screen advertises: {slug},
 * {year}, {month}, {day}, {category}. Anything else in the pattern is
 * dropped rather than emitted — a literal "{author}" in an href is a broken
 * link, and a silently shorter path is the better failure.
 */
final class PostPermalink
{
    public function __construct(private readonly string $pattern) {}

    public static function fromSettings(): self
    {
        return new self(BlogSettings::get()->permalink_pattern);
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
