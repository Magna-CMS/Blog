<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Seo;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Magna\Seo\Contracts\SeoSubjectSource;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Subjects\SeoImage;
use Magna\Seo\Subjects\SeoSubject;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\PostPermalink;

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
 *
 * Post URLs come from the same permalink pattern the public post page is
 * mounted at, so sitemap URLs are the URLs the site serves. The pattern is
 * read once per call rather than held: the registry keeps one instance of
 * this class for the life of the process.
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
        $permalink = PostPermalink::fromSettings();
        $post = $this->posts($permalink)->where('slug', $id)->first();

        return $post === null ? null : $this->build($post, $permalink);
    }

    public function chunk(callable $callback, int $size = 500): void
    {
        $permalink = PostPermalink::fromSettings();

        $this->posts($permalink)
            ->chunk($size, function ($posts) use ($callback, $permalink): void {
                $subjects = [];
                foreach ($posts as $post) {
                    $subjects[] = $this->build($post, $permalink);
                }
                $callback($subjects);
            });
    }

    public function modelClass(): string
    {
        return Post::class;
    }

    /**
     * Live public posts, with the category eager-loaded when the permalink
     * pattern reads it.
     *
     * @return Builder<Post>
     */
    private function posts(PostPermalink $permalink): Builder
    {
        $query = Post::query()->live()->public();

        return $permalink->usesCategory() ? $query->with('category:id,slug') : $query;
    }

    private function build(Post $post, PostPermalink $permalink): SeoSubject
    {
        return new SeoSubject(
            key: 'blog:'.$post->slug,
            type: SubjectType::Article,
            url: url($permalink->for($post)),
            title: $post->title,
            locale: $post->locale,
            indexable: $post->isPubliclyVisible(),
            updatedAt: DateTimeImmutable::createFromInterface($post->updated_at ?? now()),
            plainText: $post->plainText(),
            excerpt: filled($post->excerpt) ? (string) $post->excerpt : null,
            publishedAt: $post->published_at !== null
                ? DateTimeImmutable::createFromInterface($post->published_at)
                : null,
            alternates: $this->alternates($post, $permalink),
            images: $this->images($post),
        );
    }

    /**
     * Sibling translations sharing this post's translation_group, as
     * locale => absolute URL (plus the post itself), for hreflang.
     *
     * @return array<string, string>
     */
    private function alternates(Post $post, PostPermalink $permalink): array
    {
        $alternates = [$post->locale => url($permalink->for($post))];

        if ($post->translation_group === null || $post->translation_group === '') {
            return $alternates;
        }

        // Every column the permalink pattern's tokens may read.
        $siblings = $this->posts($permalink)
            ->where('translation_group', $post->translation_group)
            ->whereKeyNot($post->getKey())
            ->get(['id', 'locale', 'slug', 'published_at', 'created_at', 'category_id']);

        foreach ($siblings as $sibling) {
            $alternates[$sibling->locale] = url($permalink->for($sibling));
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
}
