<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Blocks;

use Magna\Blocks\Resolution\ResolverBudget;
use Magna\Blocks\Resolution\ResolvesBlockData;
use MagnaCms\Blog\Blocks\DataSources\PostsSource;

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
 */
final class BlogPostsBlockResolver implements ResolvesBlockData
{
    public function __construct(private readonly ResolverBudget $budget) {}

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

        $limit = is_numeric($data['limit'] ?? null) ? (int) $data['limit'] : 6;

        /*
         * Through the render budget, like every other resolver that queries
         * on a visitor's request: a page with several of these is the shape
         * this bounds, and a refused fetch renders the same empty list a
         * disabled plugin does.
         */
        $items = $this->budget->spend(
            'source',
            $source->handle(),
            fn (): array => $source->fetch(['limit' => $limit]),
        ) ?? [];

        return ['items' => $items];
    }
}
