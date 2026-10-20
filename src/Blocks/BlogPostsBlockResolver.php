<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Blocks;

use Illuminate\Contracts\Container\Container;
use Magna\Blocks\DataSources\DataSourcePage;
use Magna\Blocks\Resolution\ResolverBudget;
use Magna\Blocks\Resolution\ResolvesBlockData;
use Magna\Frontend\CacheCollector;
use Magna\Frontend\RenderContext;
use MagnaCms\Blog\Blocks\DataSources\PostsSource;
use MagnaCms\Blog\Settings\BlogSettings;

/**
 * Resolves the Blog posts block.
 *
 * Reads through the same PostsSource the Loop block uses, so there is one
 * place that decides which posts an anonymous visitor may see. This block
 * exists for DISCOVERABILITY, not for different data: "Loop" plus a
 * dropdown is not a phrase anyone searches the Add panel for when what
 * they want is their blog on the page.
 *
 * A disabled or vanished blog leaves a page with an empty list rather than
 * an error, the same degrade-don't-break stance as an unknown block.
 *
 * Since 1.2.0 the block lists the posts the request's locale serves, tags
 * the response for the page cache (`blog:posts`, plus an expiry at the next
 * scheduled go-live), and — with `pagination: pages` — serves one page of
 * `limit` posts (the blog's posts-per-page setting when the block has no
 * limit) and exposes the Magna\Frontend\Pagination at `pagination`, the key
 * themes render magna::partials.pagination from. A page past the end is
 * reported through RenderContext, which the page router turns into a 404.
 *
 * The render budget, context and collector are per-request (scoped)
 * bindings, while this resolver is registered once for the life of the
 * process; they are therefore looked up from the container on every
 * resolve, never held.
 */
final class BlogPostsBlockResolver implements ResolvesBlockData
{
    public function __construct(private readonly Container $container) {}

    public function handle(): string
    {
        return 'blog-posts';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data): array
    {
        $source = new PostsSource(featuredOnly: ($data['feed'] ?? 'latest') === 'featured');
        $context = $this->container->make(RenderContext::class);

        $limit = is_numeric($data['limit'] ?? null) && (int) $data['limit'] > 0 ? (int) $data['limit'] : null;
        $config = ['limit' => $limit ?? 6, 'locale' => $context->locale()];

        $collector = $this->container->make(CacheCollector::class);
        $collector->tag(...$source->cacheTags($config));
        $collector->expireAt($source->nextChangeAt($config));

        if (($data['pagination'] ?? 'none') !== 'pages') {
            return ['items' => $this->spend($source, fn (): array => $source->fetch($config)) ?? []];
        }

        $perPage = max(1, min(PostsSource::MAX_ITEMS, $limit ?? BlogSettings::get()->posts_per_page));

        /*
         * Through the render budget, like every other resolver that queries
         * on a visitor's request: a page with several of these is the shape
         * this bounds, and a refused fetch renders the same empty list a
         * disabled plugin does.
         */
        $page = $this->spend($source, fn (): DataSourcePage => $source->fetchPage($config, $context->page(), $perPage));
        if (! $page instanceof DataSourcePage) {
            return ['items' => []];
        }

        $pagination = $context->paginate($page->total, $perPage);

        return [
            'items' => $pagination->isOutOfRange() ? [] : $page->items,
            'pagination' => $pagination,
        ];
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $fetch
     * @return TValue|null
     */
    private function spend(PostsSource $source, callable $fetch): mixed
    {
        return $this->container->make(ResolverBudget::class)->spend('source', $source->handle(), $fetch);
    }
}
