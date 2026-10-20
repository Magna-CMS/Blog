<?php

declare(strict_types=1);

/**
 * Paginated post listings (blog 1.2.0, deferred v1 limitation A).
 *
 * PostsSource serves a page of the feed and its total through core's
 * PaginatedDataSource seam, and tells the page cache how its output changes
 * (DeclaresCacheability). The blog's own block turns `pagination: pages`
 * into a Magna\Frontend\Pagination at `_resolved.pagination`, the key Lekha
 * and the plugin's own view both render through magna::partials.pagination.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Magna\Blocks\BlockField;
use Magna\Blocks\BlockNode;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\DataSources\DeclaresCacheability;
use Magna\Blocks\DataSources\PaginatedDataSource;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Frontend\CacheCollector;
use Magna\Frontend\Pagination;
use Magna\Frontend\RenderContext;
use Magna\Settings\LocalizationSettings;
use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Blocks\DataSources\PostsSource;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

/** @param  array<string, mixed>  $attributes */
function pagedPost(array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'A post',
        'slug' => 'paged-'.bin2hex(random_bytes(4)),
        'locale' => 'en',
        'status' => PostStatus::Published,
        'visibility' => PostVisibility::Public,
        'published_at' => now()->subDay(),
    ], $attributes));
}

/** Seven live posts, "Post 7" newest. */
function sevenPagedPosts(): void
{
    foreach (range(1, 7) as $n) {
        pagedPost(['title' => 'Post '.$n, 'published_at' => now()->subDays(10 - $n)]);
    }
}

function pagedRequest(string $uri): void
{
    app(RenderContext::class)->setRequest(Request::create($uri));
}

/** @param  array<string, mixed>  $data */
function resolvePagedBlock(array $data): array
{
    $payload = app(BlockDataResolver::class)->viewPayload(BlockNode::fromArray([
        'id' => 'blk-paged',
        'block' => 'blog-posts',
        'settings' => [],
        'data' => $data,
    ]));

    return $payload['_resolved'] ?? [];
}

it('serves the feed a page at a time, with the total across all pages', function (): void {
    sevenPagedPosts();

    $source = new PostsSource;
    $second = $source->fetchPage([], 2, 3);

    expect($source)->toBeInstanceOf(PaginatedDataSource::class)
        ->and($source)->toBeInstanceOf(DeclaresCacheability::class)
        ->and($second->total)->toBe(7)
        ->and(array_column($second->items, 'title'))->toBe(['Post 4', 'Post 3', 'Post 2'])
        ->and(array_column($source->fetchPage([], 3, 3)->items, 'title'))->toBe(['Post 1']);
});

it('keeps every page behind the same visibility rules as the first', function (): void {
    pagedPost(['title' => 'Visible']);
    pagedPost(['title' => 'Draft', 'status' => PostStatus::Draft]);
    pagedPost(['title' => 'Private', 'visibility' => PostVisibility::Private]);
    pagedPost(['title' => 'Tomorrow', 'published_at' => now()->addDay()]);

    $page = (new PostsSource)->fetchPage([], 1, 10);

    expect($page->total)->toBe(1)
        ->and(array_column($page->items, 'title'))->toBe(['Visible']);
});

it('declares the purge tag and the next scheduled change', function (): void {
    pagedPost();
    $soon = now()->addHours(3)->startOfMinute();
    pagedPost(['published_at' => $soon]);
    pagedPost(['published_at' => now()->addDays(2)]);
    // A private post going live changes nothing a visitor sees.
    pagedPost(['published_at' => now()->addHour(), 'visibility' => PostVisibility::Private]);

    $source = new PostsSource;

    expect($source->cacheTags([]))->toBe(['blog:posts'])
        ->and($source->nextChangeAt([])?->getTimestamp())->toBe($soon->getTimestamp());
});

it('has no next change when nothing is scheduled', function (): void {
    pagedPost();

    expect((new PostsSource)->nextChangeAt([]))->toBeNull();
});

it('honours a locale in its config', function (): void {
    $settings = LocalizationSettings::get();
    $settings->available_locales = ['en', 'fr'];
    $settings->fallback_locale = 'en';
    $settings->save();

    pagedPost(['title' => 'English']);
    pagedPost(['title' => 'Français', 'locale' => 'fr']);

    $source = new PostsSource;

    expect(array_column($source->fetch(['locale' => 'fr']), 'title'))->toBe(['Français'])
        ->and(array_column($source->fetchPage(['locale' => 'en'], 1, 10)->items, 'title'))->toBe(['English'])
        // No locale asked for: every live post, as before 1.2.0.
        ->and($source->fetch([]))->toHaveCount(2);
});

it('exposes the pagination at _resolved.pagination, a page of `limit` posts', function (): void {
    sevenPagedPosts();
    pagedRequest('/news?page=2');

    $resolved = resolvePagedBlock(['limit' => 3, 'pagination' => 'pages']);
    $pagination = $resolved['pagination'] ?? null;

    expect($pagination)->toBeInstanceOf(Pagination::class)
        ->and($pagination->page)->toBe(2)
        ->and($pagination->perPage)->toBe(3)
        ->and($pagination->lastPage)->toBe(3)
        ->and($pagination->previousUrl())->toBe('/news')
        ->and($pagination->nextUrl())->toBe('/news?page=3')
        ->and(array_column($resolved['items'], 'title'))->toBe(['Post 4', 'Post 3', 'Post 2'])
        ->and(app(RenderContext::class)->paginated())->toBeTrue();
});

it('falls back to the posts-per-page setting when the block has no limit', function (): void {
    $settings = BlogSettings::get();
    $settings->posts_per_page = 4;
    $settings->save();

    sevenPagedPosts();
    pagedRequest('/news');

    $resolved = resolvePagedBlock(['limit' => '', 'pagination' => 'pages']);

    expect($resolved['pagination']->perPage)->toBe(4)
        ->and($resolved['items'])->toHaveCount(4);
});

it('flags a page past the end so the page answers 404', function (): void {
    sevenPagedPosts();
    pagedRequest('/news?page=9');

    $resolved = resolvePagedBlock(['limit' => 3, 'pagination' => 'pages']);

    expect(app(RenderContext::class)->outOfRange())->toBeTrue()
        ->and($resolved['items'])->toBe([]);
});

it('leaves an unpaginated block exactly as it was', function (): void {
    sevenPagedPosts();
    pagedRequest('/news?page=2');

    $resolved = resolvePagedBlock(['limit' => 3]);

    expect($resolved)->not->toHaveKey('pagination')
        ->and(array_column($resolved['items'], 'title'))->toBe(['Post 7', 'Post 6', 'Post 5'])
        // ?page=2 that nothing paginated: the page cache must not key it.
        ->and(app(RenderContext::class)->paginated())->toBeFalse();
});

it('tags the response and reports when a scheduled post will change it', function (): void {
    pagedPost();
    $soon = now()->addHour()->startOfMinute();
    pagedPost(['published_at' => $soon]);
    pagedRequest('/news');

    resolvePagedBlock(['limit' => 3]);
    $collector = app(CacheCollector::class);

    expect($collector->tags())->toContain('blog:posts')
        ->and($collector->expiresAt()?->getTimestamp())->toBe($soon->getTimestamp());
});

it('gives the block a pagination field', function (): void {
    $fields = app(BlockRegistry::class)->get('blog-posts')?->fields ?? [];

    expect(array_map(fn (BlockField $field): string => $field->handle, $fields))->toContain('pagination');
});

it('renders the pagination nav through the shared partial', function (): void {
    $html = Blade::render(
        (string) file_get_contents(__DIR__.'/../../resources/block-views/blocks/blog-posts.blade.php'),
        ['block' => [
            'data' => [],
            '_resolved' => [
                'items' => [['title' => 'One', 'url' => '/blog/one']],
                'pagination' => new Pagination(2, 1, 3, '/news'),
            ],
        ]],
    );

    expect($html)->toContain('magna-pagination')
        ->and($html)->toContain('href="/news?page=3"')
        ->and($html)->toContain('href="/news"');
});
