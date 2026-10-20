<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Frontend;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Magna\Frontend\FrontendPageDataProvider;
use Magna\Frontend\FrontendPageResult;
use Magna\Frontend\RenderContext;
use MagnaCms\Blog\Blocks\DataSources\PostsSource;
use MagnaCms\Blog\Http\Resources\DynamicBlockResolver;
use MagnaCms\Blog\Listeners\PurgePublicBlogCache;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Seo\PostSeoMeta;
use MagnaCms\Blog\Support\BlockRenderer;
use MagnaCms\Blog\Support\CommentPolicy;
use MagnaCms\Blog\Support\PostPermalink;

/**
 * Resolves the post a public post-page request names, and everything its
 * view shows — the view itself never queries.
 *
 * Returns null (Pages then falls through to its redirects and themed 404)
 * for anything a visitor may not see: unknown slug, draft, scheduled,
 * private or password-protected (the live() + public() scopes), a post not
 * served in the request's locale (a French post answers at /fr/…, never at
 * the bare path), and any path that is not the post's canonical one. The
 * last rule matters for multi-token patterns — `/news/{year}/{slug}` would
 * otherwise answer for every year, an endless supply of duplicate URLs for
 * one post.
 *
 * A guest's page is offered to the page cache under `blog:post:{id}` and
 * `blog:posts` (PurgePublicBlogCache purges both), expiring by itself when
 * the next post goes live (its prev/next links change) or the comment form
 * closes. A signed-in visitor's page, or one carrying a flash notice or
 * form errors, is never offered: what it shows is that visitor's alone.
 */
final class PostPageProvider implements FrontendPageDataProvider
{
    public function __construct(
        private readonly DynamicBlockResolver $dynamic,
        private readonly BlockRenderer $renderer,
        private readonly RenderContext $context,
        private readonly PostCommentThread $comments,
        private readonly CommentPolicy $commentPolicy,
        private readonly Request $request,
        private readonly AuthFactory $auth,
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
        $locale = $this->context->locale();
        $post = $this->find($slug, $permalink, $locale);

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
                'previous' => $this->neighbour($post, 'prev', $permalink, $locale),
                'next' => $this->neighbour($post, 'next', $permalink, $locale),
                'stylesheet' => $this->stylesheet(),
                'comments' => $this->comments->build($post, $this->request, $this->auth->guard()->check()),
            ],
            title: $post->title,
            metaDescription: $this->description($post),
            cacheable: $this->cacheable(),
            cacheTags: [PurgePublicBlogCache::postTag($post->id), PostsSource::CACHE_TAG],
            expiresAt: $this->expiresAt($post, $locale),
            localeAlternates: $this->alternates($post, $permalink),
        );
    }

    private function find(string $slug, PostPermalink $permalink, string $locale): ?Post
    {
        $with = ['author:id,name', 'tags:id,name,slug'];
        if ($permalink->usesCategory()) {
            $with[] = 'category:id,slug';
        }

        return Post::query()->live()->public()->servedIn($locale)->where('slug', $slug)->with($with)->first();
    }

    /**
     * A neighbour as the view needs it: its title and an already-built URL.
     *
     * @return array{title: string, url: string}|null
     */
    private function neighbour(Post $post, string $direction, PostPermalink $permalink, string $locale): ?array
    {
        $neighbour = $post->adjacent($direction, $locale);
        if ($neighbour === null) {
            return null;
        }

        if ($permalink->usesCategory()) {
            $neighbour->loadMissing('category:id,slug');
        }

        return ['title' => $neighbour->title, 'url' => $permalink->url($neighbour)];
    }

    /** Only a guest's plain GET is the same for every visitor. */
    private function cacheable(): bool
    {
        if ($this->auth->guard()->check()) {
            return false;
        }

        if (! $this->request->hasSession()) {
            return true;
        }

        $session = $this->request->session();

        return $session->get('_flash.old', []) === []
            && $session->get('_flash.new', []) === []
            && ! $session->has('errors');
    }

    /** The earlier of the next go-live in this locale and the comment form closing. */
    private function expiresAt(Post $post, string $locale): ?CarbonImmutable
    {
        $goLive = Post::nextGoLiveAt($locale);
        $closes = $this->commentPolicy->open($post) ? $this->commentPolicy->closesAt($post) : null;

        if ($goLive === null || $closes === null) {
            return $goLive ?? $closes;
        }

        return $goLive->lessThan($closes) ? $goLive : $closes;
    }

    /**
     * The post and its live translations (same translation_group), as
     * content language => URL — the hreflang set.
     *
     * @return array<string, string>
     */
    private function alternates(Post $post, PostPermalink $permalink): array
    {
        if ($post->translation_group === null || $post->translation_group === '') {
            return [];
        }

        $alternates = [$post->locale => $permalink->url($post)];

        $siblings = Post::query()
            ->live()
            ->public()
            ->whereNull('password')
            ->where('translation_group', $post->translation_group)
            ->whereKeyNot($post->getKey())
            ->when($permalink->usesCategory(), fn ($query) => $query->with('category:id,slug'))
            ->orderBy('locale')
            ->get(['id', 'slug', 'locale', 'published_at', 'created_at', 'category_id']);

        foreach ($siblings as $sibling) {
            $alternates[$sibling->locale] ??= $permalink->url($sibling);
        }

        return $alternates;
    }

    /** The post's own SEO description when the SEO plugin holds one, else its summary. */
    private function description(Post $post): ?string
    {
        $seo = PostSeoMeta::read($post)['description'] ?? null;
        if (is_string($seo) && trim($seo) !== '') {
            return trim($seo);
        }

        return $post->summary();
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
