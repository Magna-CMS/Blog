<?php

declare(strict_types=1);

/**
 * The blog's own block, in the builder's Add panel.
 *
 * Reported as "i cant able to see the blocks from the blog plugin on the
 * left panel of the pagebuilder". The data sources already put posts on a
 * page, but only through the Loop block and its dropdown — and "Loop" is
 * not what somebody looks for when what they want is their blog. Same
 * data, behind a name that can be found.
 */

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Magna\Blocks\BlockNode;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Models\Post;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

function blockPost(array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'A post',
        'slug' => 'a-post-'.bin2hex(random_bytes(4)),
        'locale' => 'en',
        'status' => PostStatus::Published,
        'visibility' => PostVisibility::Public,
        'published_at' => now()->subDay(),
    ], $attributes));
}

it('puts a findable block in the panel, stamped with the plugin that shipped it', function (): void {
    $definition = app(BlockRegistry::class)->get('blog-posts');

    expect($definition)->not->toBeNull()
        ->and($definition->label)->toBe('Blog posts')
        // Its own category, so it reads as the blog's rather than hiding
        // among the generic dynamic blocks.
        ->and($definition->category)->toBe('blog')
        ->and($definition->sourcePlugin)->toBe('magna-cms/blog');
});

it('has a view the standard resolution chain can find', function (): void {
    // A plugin's block views have no namespace of their own in the
    // resolution chain, so the plugin exposes them on the shared one. This
    // asserts the wiring, not the file: a block whose view cannot be
    // resolved renders as nothing at all.
    expect(View::exists('magna::blocks.blog-posts'))->toBeTrue();
});

/** What the renderer actually asks for: a node in, a view payload out. */
function resolveBlogPostsBlock(array $data): array
{
    $payload = app(BlockDataResolver::class)->viewPayload(BlockNode::fromArray([
        'id' => 'blk-blog',
        'block' => 'blog-posts',
        'settings' => [],
        'data' => $data,
    ]));

    return $payload['_resolved']['items'] ?? [];
}

it('resolves to the posts a visitor may see, and nothing else', function (): void {
    blockPost(['title' => 'Visible']);
    blockPost(['title' => 'Draft', 'status' => PostStatus::Draft]);
    blockPost(['title' => 'Private', 'visibility' => PostVisibility::Private]);

    $items = resolveBlogPostsBlock(['limit' => 5, 'feed' => 'latest']);

    expect(array_column($items, 'title'))->toBe(['Visible']);
});

it('shows featured posts only when the block asks for them', function (): void {
    blockPost(['title' => 'Ordinary']);
    blockPost(['title' => 'Sticky', 'is_featured' => true]);

    expect(array_column(resolveBlogPostsBlock(['feed' => 'featured']), 'title'))->toBe(['Sticky']);
});

it('renders the posts it resolved, escaped', function (): void {
    $html = Blade::render(
        (string) file_get_contents(__DIR__.'/../../resources/block-views/blocks/blog-posts.blade.php'),
        [
            'block' => [
                'data' => ['heading' => 'From the blog'],
                '_resolved' => ['items' => [
                    ['title' => '<script>alert(1)</script>', 'url' => '/blog/x', 'date' => '2026-08-24'],
                ]],
            ],
        ],
    );

    expect($html)->toContain('From the blog')
        ->and($html)->toContain('href="/blog/x"')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('renders a gap rather than a broken box when there are no posts', function (): void {
    $html = Blade::render(
        (string) file_get_contents(__DIR__.'/../../resources/block-views/blocks/blog-posts.blade.php'),
        ['block' => ['data' => [], '_resolved' => ['items' => []]]],
    );

    expect($html)->not->toContain('<ul');
});
