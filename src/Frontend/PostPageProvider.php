<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Frontend;

use Illuminate\Support\Facades\Storage;
use Magna\Frontend\FrontendPageDataProvider;
use Magna\Frontend\FrontendPageResult;
use MagnaCms\Blog\Http\Resources\DynamicBlockResolver;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Seo\PostSeoMeta;
use MagnaCms\Blog\Support\BlockRenderer;
use MagnaCms\Blog\Support\PostPermalink;

/**
 * Resolves the post a public post-page request names, and everything its
 * view shows — the view itself never queries.
 *
 * Returns null (Pages then falls through to its redirects and themed 404)
 * for anything a visitor may not see: unknown slug, draft, scheduled,
 * private or password-protected (the live() + public() scopes), and any
 * path that is not the post's canonical one. The last rule matters for
 * multi-token patterns — `/news/{year}/{slug}` would otherwise answer for
 * every year, an endless supply of duplicate URLs for one post.
 */
final class PostPageProvider implements FrontendPageDataProvider
{
    public function __construct(
        private readonly DynamicBlockResolver $dynamic,
        private readonly BlockRenderer $renderer,
    ) {}

    /**
     * @param  array<string, string>  $params
     */
    public function resolve(array $params): ?FrontendPageResult
    {
        $slug = $params['slug'] ?? null;
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $permalink = PostPermalink::fromSettings();
        $post = $this->find($slug, $permalink);

        if ($post === null || $permalink->for($post) !== $permalink->pathFromParams($params)) {
            return null;
        }

        $content = is_array($post->content) ? $post->content : ['blocks' => []];

        return new FrontendPageResult(
            data: [
                'post' => $post,
                'bodyHtml' => $this->renderer->render($this->dynamic->resolve($content, $post)),
                'readingTime' => $post->readingTimeMinutes(),
                'authorName' => $post->author?->name,
                'publishedAt' => $post->published_at ?? $post->created_at,
                'featuredImage' => $this->imageUrl($post->featured_image),
                'tags' => $post->tags->pluck('name')->all(),
                'previous' => $this->neighbour($post, 'prev', $permalink),
                'next' => $this->neighbour($post, 'next', $permalink),
                'stylesheet' => $this->stylesheet(),
            ],
            title: $post->title,
            metaDescription: $this->description($post),
        );
    }

    private function find(string $slug, PostPermalink $permalink): ?Post
    {
        $with = ['author:id,name', 'tags:id,name,slug'];
        if ($permalink->usesCategory()) {
            $with[] = 'category:id,slug';
        }

        return Post::query()->live()->public()->where('slug', $slug)->with($with)->first();
    }

    /**
     * A neighbour as the view needs it: its title and an already-built URL.
     *
     * @return array{title: string, url: string}|null
     */
    private function neighbour(Post $post, string $direction, PostPermalink $permalink): ?array
    {
        $neighbour = $post->adjacent($direction);
        if ($neighbour === null) {
            return null;
        }

        if ($permalink->usesCategory()) {
            $neighbour->loadMissing('category:id,slug');
        }

        return ['title' => $neighbour->title, 'url' => $permalink->for($neighbour)];
    }

    /** The post's own SEO description when the SEO plugin holds one, else its excerpt. */
    private function description(Post $post): ?string
    {
        $seo = PostSeoMeta::read($post)['description'] ?? null;
        if (is_string($seo) && trim($seo) !== '') {
            return trim($seo);
        }

        $excerpt = trim((string) $post->excerpt);

        return $excerpt !== '' ? $excerpt : null;
    }

    private function imageUrl(?string $path): ?string
    {
        return $path === null || trim($path) === '' ? null : Storage::disk('public')->url($path);
    }

    /**
     * The block stylesheet, cache-busted by content hash, when it has been
     * published — the same file and pattern the admin preview links.
     */
    private function stylesheet(): ?string
    {
        $file = public_path('css/magna-cms/blog/blog-editor.css');
        if (! is_file($file)) {
            return null;
        }

        return asset('css/magna-cms/blog/blog-editor.css').'?v='.substr((string) md5_file($file), 0, 8);
    }
}
