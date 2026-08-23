<?php

declare(strict_types=1);

use Magna\Blocks\DataSources\DataSourceRegistry;
use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Blocks\DataSources\PostsSource;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

/**
 * A post with sensible defaults, so each test states only what it is about.
 *
 * @param  array<string, mixed>  $attributes
 */
function sourcePost(array $attributes = []): Post
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

/** @return list<array<string, string>> */
function fetchPosts(bool $featured = false, int $limit = 6): array
{
    return (new PostsSource(featuredOnly: $featured))->fetch(['limit' => $limit]);
}

it('registers both post feeds with the data source registry', function (): void {
    $registry = app(DataSourceRegistry::class);

    expect($registry->get('blog.posts'))->not->toBeNull()
        ->and($registry->get('blog.featured'))->not->toBeNull()
        ->and($registry->get('blog.posts')?->label())->toBe('Blog — latest posts');
});

/*
 * The security boundary. fetch() runs on the public render path for an
 * anonymous visitor, so every one of these must be invisible — and no
 * argument to fetch() may bring any of them back.
 */
it('never exposes a post an anonymous visitor may not read', function (): void {
    $visible = sourcePost(['title' => 'Visible']);

    sourcePost(['title' => 'Draft', 'status' => PostStatus::Draft]);
    sourcePost(['title' => 'Pending', 'status' => PostStatus::PendingReview]);
    sourcePost(['title' => 'Archived', 'status' => PostStatus::Archived]);
    sourcePost(['title' => 'Scheduled', 'published_at' => now()->addWeek()]);
    sourcePost(['title' => 'Private', 'visibility' => PostVisibility::Private]);
    sourcePost(['title' => 'Password', 'visibility' => PostVisibility::Password, 'password' => 'secret']);

    // Marked public but carrying a password anyway: the row is inconsistent,
    // and the safe reading of an inconsistent row is "do not publish it".
    sourcePost(['title' => 'Stray password', 'password' => 'secret']);

    sourcePost(['title' => 'Trashed'])->delete();

    $titles = array_column(fetchPosts(), 'title');

    expect($titles)->toBe(['Visible'])
        ->and($visible->title)->toBe('Visible');
});

it('returns newest first and clamps the limit to the source ceiling', function (): void {
    foreach (range(1, 4) as $day) {
        sourcePost(['title' => 'Post '.$day, 'published_at' => now()->subDays(5 - $day)]);
    }

    expect(array_column(fetchPosts(limit: 2), 'title'))->toBe(['Post 4', 'Post 3']);

    // A caller asking for more than the ceiling gets the ceiling, not an
    // unbounded query — the source does not trust the resolver's clamp.
    expect(fetchPosts(limit: 999))->toHaveCount(4);
});

it('separates the featured feed from the latest feed', function (): void {
    sourcePost(['title' => 'Ordinary']);
    sourcePost(['title' => 'Sticky', 'is_featured' => true]);

    expect(array_column(fetchPosts(featured: true), 'title'))->toBe(['Sticky'])
        ->and(array_column(fetchPosts(), 'title'))->toHaveCount(2);
});

it('builds each item under the loop view conventional keys', function (): void {
    sourcePost([
        'title' => 'Shaped',
        'slug' => 'shaped',
        'excerpt' => 'A summary.',
        'featured_image' => 'blog/cover.jpg',
        'published_at' => now()->setDate(2026, 3, 4)->startOfDay(),
    ]);

    $item = fetchPosts()[0];

    expect($item['title'])->toBe('Shaped')
        ->and($item['url'])->toBe('/blog/shaped')
        ->and($item['description'])->toBe('A summary.')
        ->and($item['date'])->toBe('2026-03-04')
        ->and($item['image'])->toContain('blog/cover.jpg');
});

it('omits keys it has no value for rather than sending null', function (): void {
    sourcePost(['title' => 'Bare', 'slug' => 'bare', 'excerpt' => null, 'featured_image' => null]);

    $item = fetchPosts()[0];

    // The contract is a map of strings; the view checks with empty(), so a
    // null would be a type violation for no gain.
    expect($item)->toHaveKeys(['title', 'url', 'date'])
        ->and($item)->not->toHaveKey('description')
        ->and($item)->not->toHaveKey('image');

    foreach ($item as $value) {
        expect($value)->toBeString();
    }
});

it('follows the configured permalink pattern', function (): void {
    $settings = BlogSettings::get();
    $settings->permalink_pattern = '/news/{year}/{category}/{slug}';
    $settings->save();

    $category = Category::create(['name' => 'Releases', 'slug' => 'releases']);

    sourcePost([
        'slug' => 'v2-is-out',
        'category_id' => $category->id,
        'published_at' => now()->setDate(2026, 1, 9)->startOfDay(),
    ]);

    expect(fetchPosts()[0]['url'])->toBe('/news/2026/releases/v2-is-out');
});

it('drops unknown tokens instead of emitting a broken path', function (): void {
    $settings = BlogSettings::get();
    $settings->permalink_pattern = '/blog/{author}/{slug}';
    $settings->save();

    // {author} is not a token the pattern reader knows. A literal "{author}"
    // in an href is a broken link, so the segment vanishes and the empty
    // slash it leaves behind is collapsed.
    sourcePost(['slug' => 'orphan']);

    expect(fetchPosts()[0]['url'])->toBe('/blog/orphan');
});

it('reads the category relation only when the pattern asks for it', function (): void {
    // Posts always have a category (PostObserver assigns the `uncategorised`
    // fallback), so the cost of resolving one is real on every render. A
    // pattern with no {category} must not pay it — asserted here by the
    // suite's Model::preventLazyLoading(), which turns the N+1 this would
    // otherwise be into a failure.
    foreach (range(1, 3) as $index) {
        sourcePost(['title' => 'Post '.$index]);
    }

    expect(fetchPosts())->toHaveCount(3);

    $settings = BlogSettings::get();
    $settings->permalink_pattern = '/blog/{category}/{slug}';
    $settings->save();

    // And when it does ask, the relation is eager-loaded rather than lazily
    // hit once per row.
    expect(fetchPosts()[0]['url'])->toContain('/blog/uncategorised/');
});
