<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Seo;

use Illuminate\Contracts\Foundation\Application;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Support\SeoMetaRepository;
use MagnaCms\Blog\Models\Post;

/**
 * The blog's decoupled bridge to the optional Magna SEO plugin for per-post SEO
 * meta. SEO is capability-detected, never a hard dependency: the `use` imports
 * above are compile-time aliases that never autoload, and every SEO symbol is
 * only ever *touched* — via app()->make() or `new` — behind {@see self::active()}
 * or a class_exists guard, so the blog runs unchanged when SEO is absent. This is
 * the same pattern the docs plugin uses for its own SEO integration.
 *
 * Reads/writes go through the SEO plugin's SeoMetaRepository, whose upsert sets
 * the morph keys explicitly and only fills whitelisted columns — so the blog can
 * never re-point a meta row or write a column the SEO plugin does not own.
 */
final class PostSeoMeta
{
    /**
     * Whether the SEO plugin is installed AND enabled. The registry singleton is
     * bound in SeoPlugin::register(), so its presence in the container is the
     * canonical "SEO is active" signal — cheaper than a plugins-table read and
     * immune to the plugin's manifest-name spelling.
     */
    public static function active(): bool
    {
        return app()->bound(SeoSourceRegistry::class);
    }

    /**
     * The post's stored SEO overrides shaped for the builder's SEO form, or
     * sensible defaults (indexable, empty) when SEO is inactive or the post has no
     * meta row yet.
     *
     * @return array<string, mixed>
     */
    public static function read(Post $post): array
    {
        if (! self::active()) {
            return self::blank();
        }

        $meta = app(SeoMetaRepository::class)->for(Post::class, (string) $post->getKey());

        if ($meta === null) {
            return self::blank();
        }

        $keywords = is_array($meta->focus_keywords) ? $meta->focus_keywords : [];

        return [
            'title' => $meta->title,
            'description' => $meta->description,
            'canonical_url' => $meta->canonical_url,
            'focus_keyword' => is_string($keywords[0] ?? null) ? $keywords[0] : null,
            'robots_index' => $meta->robots_index,
            'robots_follow' => $meta->robots_follow,
            'og_title' => $meta->og_title,
            'og_description' => $meta->og_description,
            'twitter_card' => $meta->twitter_card,
            'twitter_title' => $meta->twitter_title,
            'twitter_description' => $meta->twitter_description,
        ];
    }

    /**
     * Persist the SEO form values for a post. A no-op when SEO is inactive, so a
     * save on a site without the SEO plugin simply drops the (never-shown) fields.
     *
     * @param  array<string, mixed>  $data
     */
    public static function write(Post $post, array $data): void
    {
        if (! self::active()) {
            return;
        }

        $keyword = trim((string) ($data['focus_keyword'] ?? ''));

        app(SeoMetaRepository::class)->upsert(Post::class, (string) $post->getKey(), [
            'title' => self::nullableString($data['title'] ?? null),
            'description' => self::nullableString($data['description'] ?? null),
            'canonical_url' => self::nullableString($data['canonical_url'] ?? null),
            'focus_keywords' => $keyword !== '' ? [$keyword] : null,
            'robots_index' => (bool) ($data['robots_index'] ?? true),
            'robots_follow' => (bool) ($data['robots_follow'] ?? true),
            'og_title' => self::nullableString($data['og_title'] ?? null),
            'og_description' => self::nullableString($data['og_description'] ?? null),
            'twitter_card' => self::nullableString($data['twitter_card'] ?? null),
            'twitter_title' => self::nullableString($data['twitter_title'] ?? null),
            'twitter_description' => self::nullableString($data['twitter_description'] ?? null),
        ]);
    }

    /**
     * Register the blog's SEO subject source (sitemaps + scans) when SEO is
     * active. class_exists-guarded and idempotent, mirroring the docs plugin; a
     * no-op with SEO absent. Called from BlogPlugin::boot().
     */
    public static function registerSource(Application $app): void
    {
        if (! class_exists(SeoSourceRegistry::class)) {
            return;
        }

        $app->afterResolving(SeoSourceRegistry::class, static function (SeoSourceRegistry $registry): void {
            if (! $registry->has('blog')) {
                $registry->register(new BlogSeoSource);
            }
        });
    }

    /**
     * The empty SEO form shape: indexable by default, everything else blank. Used
     * to seed the builder on create and when a post has no meta row yet.
     *
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return [
            'title' => null,
            'description' => null,
            'canonical_url' => null,
            'focus_keyword' => null,
            'robots_index' => true,
            'robots_follow' => true,
            'og_title' => null,
            'og_description' => null,
            'twitter_card' => null,
            'twitter_title' => null,
            'twitter_description' => null,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $string = is_string($value) ? trim($value) : '';

        return $string === '' ? null : $string;
    }
}
