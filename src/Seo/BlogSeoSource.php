<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Seo;

use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use Magna\Seo\Contracts\SeoSubjectSource;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;
use MagnaCms\Blog\Models\Post;

/**
 * Maps blog posts into the SEO subject model — the whole of the blog↔SEO
 * integration surface for sitemaps and scans. Blog registers this into the SEO
 * source registry from BlogPlugin::boot(), guarded by class_exists, so the blog
 * plugin never hard-depends on SEO: this class is autoloaded only when the SEO
 * plugin is present, and referencing the Magna\Seo\* types below is therefore
 * safe (the file is never loaded when SEO is absent).
 *
 * A post's own SEO overrides (title, description, …) live in magna_seo_meta and
 * are applied by the SEO meta pipeline as author overrides; this source only
 * carries the post's content facts.
 */
final class BlogSeoSource implements SeoSubjectSource
{
    public function handle(): string
    {
        return 'blog';
    }

    public function label(): string
    {
        return 'Blog';
    }

    public function resolve(string $id): ?SeoSubject
    {
        $post = Post::query()
            ->live()
            ->public()
            ->where('slug', $id)
            ->first();

        return $post === null ? null : $this->build($post);
    }

    public function chunk(callable $callback, int $size = 500): void
    {
        Post::query()
            ->live()
            ->public()
            ->chunk($size, function ($posts) use ($callback): void {
                $subjects = [];
                foreach ($posts as $post) {
                    $subjects[] = $this->build($post);
                }
                $callback($subjects);
            });
    }

    public function modelClass(): string
    {
        return Post::class;
    }

    private function build(Post $post): SeoSubject
    {
        return new SeoSubject(
            key: 'blog:'.$post->slug,
            type: SubjectType::Article,
            url: $this->postUrl($post->slug),
            title: $post->title,
            locale: $post->locale,
            indexable: $post->isPubliclyVisible(),
            updatedAt: DateTimeImmutable::createFromInterface($post->updated_at ?? now()),
            plainText: $post->plainText(),
            excerpt: filled($post->excerpt) ? (string) $post->excerpt : null,
            publishedAt: $post->published_at !== null
                ? DateTimeImmutable::createFromInterface($post->published_at)
                : null,
            alternates: $this->alternates($post),
            images: $this->images($post),
        );
    }

    /**
     * Sibling translations sharing this post's translation_group, as
     * locale => absolute URL (plus the post itself), for hreflang.
     *
     * @return array<string, string>
     */
    private function alternates(Post $post): array
    {
        $alternates = [$post->locale => $this->postUrl($post->slug)];

        if ($post->translation_group === null || $post->translation_group === '') {
            return $alternates;
        }

        $siblings = Post::query()
            ->live()
            ->public()
            ->where('translation_group', $post->translation_group)
            ->whereKeyNot($post->getKey())
            ->get(['locale', 'slug']);

        foreach ($siblings as $sibling) {
            $alternates[$sibling->locale] = $this->postUrl($sibling->slug);
        }

        return $alternates;
    }

    /**
     * @return list<SeoImage>
     */
    private function images(Post $post): array
    {
        $path = is_string($post->featured_image) ? trim($post->featured_image) : '';
        if ($path === '') {
            return [];
        }

        return [new SeoImage(url: Storage::disk('public')->url($path))];
    }

    /**
     * Absolute URL for a post. Built from the fixed /blog/{slug} structure rather
     * than a named route, so it resolves the same in web, console and queue
     * contexts (sitemap generation) regardless of route registration.
     */
    private function postUrl(string $slug): string
    {
        return url('blog/'.$slug);
    }
}
