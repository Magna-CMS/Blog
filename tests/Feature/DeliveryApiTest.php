<?php

declare(strict_types=1);

use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Models\Tag;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\ViewCounter;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

/** Mint a delivery-scope bearer token, as the delivery routes require one. */
function blogDeliveryToken(): string
{
    $user = User::factory()->create();
    $token = $user->createToken('test-delivery', ['delivery']);
    $token->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $token->plainTextToken;
}

function makePost(array $overrides = []): Post
{
    return Post::create(array_merge([
        'title' => 'A published post',
        'slug' => 'a-published-post',
        'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Hello world']]]],
        'status' => 'published',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
    ], $overrides));
}

it('lists only published public posts with pagination meta', function (): void {
    makePost(['slug' => 'live-one', 'title' => 'Live one']);
    makePost(['slug' => 'draft-one', 'title' => 'Draft one', 'status' => 'draft', 'published_at' => null]);
    makePost(['slug' => 'private-one', 'title' => 'Private one', 'visibility' => 'private']);
    makePost(['slug' => 'future-one', 'title' => 'Future one', 'published_at' => now()->addWeek()]);

    $response = $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts');

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.slug', 'live-one')
        ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
});

it('serves a single published post with its content', function (): void {
    makePost(['slug' => 'read-me', 'title' => 'Read me']);

    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts/read-me')
        ->assertOk()
        ->assertJsonPath('data.slug', 'read-me')
        ->assertJsonPath('data.title', 'Read me');
});

it('returns 404 for a draft or private post slug', function (): void {
    makePost(['slug' => 'hidden-draft', 'status' => 'draft', 'published_at' => null]);
    makePost(['slug' => 'hidden-private', 'visibility' => 'private']);

    $token = blogDeliveryToken();
    $this->withToken($token)->getJson('/api/v1/blog/posts/hidden-draft')->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/blog/posts/hidden-private')->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/blog/posts/does-not-exist')->assertNotFound();
});

it('searches posts over title, excerpt and block text', function (): void {
    makePost(['slug' => 'about-laravel', 'title' => 'All about Laravel']);
    makePost(['slug' => 'excerpt-hit', 'title' => 'Unrelated', 'excerpt' => 'deep dive into Laravel scopes']);
    makePost([
        'slug' => 'body-hit',
        'title' => 'Another title',
        'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'the word Laravel lives in the body']]]],
    ]);
    makePost(['slug' => 'no-hit', 'title' => 'Django guide', 'content' => ['blocks' => []]]);

    $response = $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts?search=Laravel');

    $response->assertOk()->assertJsonPath('meta.total', 3);
    $slugs = collect($response->json('data'))->pluck('slug')->all();
    expect($slugs)->not->toContain('no-hit');
});

it('narrows multi-word searches with AND semantics', function (): void {
    makePost(['slug' => 'both', 'title' => 'Laravel scopes explained']);
    makePost(['slug' => 'one-only', 'title' => 'Laravel routing']);

    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts?search=Laravel+scopes')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.slug', 'both');
});

it('filters posts by category slug', function (): void {
    $news = Category::create(['name' => 'News', 'slug' => 'news']);
    $other = Category::create(['name' => 'Other', 'slug' => 'other']);
    makePost(['slug' => 'in-news', 'title' => 'In news', 'category_id' => $news->id]);
    makePost(['slug' => 'in-other', 'title' => 'In other', 'category_id' => $other->id]);

    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts?category=news')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.slug', 'in-news');
});

it('filters posts by tag slug', function (): void {
    $tag = Tag::create(['name' => 'PHP', 'slug' => 'php']);
    $tagged = makePost(['slug' => 'tagged', 'title' => 'Tagged']);
    $tagged->tags()->attach($tag->id);
    makePost(['slug' => 'untagged', 'title' => 'Untagged']);

    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts?tag=php')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.slug', 'tagged');
});

it('filters posts by publish year and month', function (): void {
    makePost(['slug' => 'jan', 'title' => 'January', 'published_at' => '2025-01-15 10:00:00']);
    makePost(['slug' => 'feb', 'title' => 'February', 'published_at' => '2025-02-15 10:00:00']);

    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts?year=2025&month=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.slug', 'jan');
});

it('sorts posts by title ascending when asked', function (): void {
    makePost(['slug' => 'zed', 'title' => 'Zed', 'published_at' => now()->subDays(1)]);
    makePost(['slug' => 'alpha', 'title' => 'Alpha', 'published_at' => now()->subDays(2)]);

    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts?sort=title')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'alpha')
        ->assertJsonPath('data.1.slug', 'zed');
});

it('rejects an unknown sort key', function (): void {
    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts?sort=drop_table')
        ->assertStatus(422);
});

it('rejects month without year', function (): void {
    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts?month=3')
        ->assertStatus(422);
});

it('lists categories as a tree with live public post counts', function (): void {
    $parent = Category::create(['name' => 'Tech', 'slug' => 'tech']);
    $child = Category::create(['name' => 'Laravel', 'slug' => 'laravel', 'parent_id' => $parent->id]);
    Category::create(['name' => 'Empty', 'slug' => 'empty']);

    makePost(['slug' => 'p1', 'title' => 'P1', 'category_id' => $child->id]);
    makePost(['slug' => 'draft', 'title' => 'Draft', 'category_id' => $child->id, 'status' => 'draft', 'published_at' => null]);

    $response = $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/categories');

    $response->assertOk();
    $tech = collect($response->json('data'))->firstWhere('slug', 'tech');
    expect($tech['children'][0]['slug'])->toBe('laravel');
    expect($tech['children'][0]['post_count'])->toBe(1); // draft excluded
});

it('lists only tags that have a live public post', function (): void {
    $used = Tag::create(['name' => 'Used', 'slug' => 'used']);
    Tag::create(['name' => 'Unused', 'slug' => 'unused']);
    makePost(['slug' => 'tp', 'title' => 'TP'])->tags()->attach($used->id);

    $response = $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/tags');

    $response->assertOk();
    $slugs = collect($response->json('data'))->pluck('slug')->all();
    expect($slugs)->toContain('used')->not->toContain('unused');
});

it('exposes adjacent prev/next neighbours on a single post', function (): void {
    makePost(['slug' => 'older', 'title' => 'Older', 'published_at' => now()->subDays(3)]);
    makePost(['slug' => 'middle', 'title' => 'Middle', 'published_at' => now()->subDays(2)]);
    makePost(['slug' => 'newer', 'title' => 'Newer', 'published_at' => now()->subDays(1)]);

    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts/middle')
        ->assertOk()
        ->assertJsonPath('data.adjacent.prev.slug', 'older')
        ->assertJsonPath('data.adjacent.next.slug', 'newer');
});

it('returns null adjacent neighbours at the ends', function (): void {
    makePost(['slug' => 'solo', 'title' => 'Solo']);

    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts/solo')
        ->assertOk()
        ->assertJsonPath('data.adjacent.prev', null)
        ->assertJsonPath('data.adjacent.next', null);
});

it('exposes only allowlisted public meta, typed, and hides the rest', function (): void {
    $settings = BlogSettings::get();
    $settings->public_meta = ['subtitle', 'rating', 'featured'];
    $settings->save();

    $post = makePost(['slug' => 'meta-post', 'title' => 'Meta post']);
    $post->meta()->createMany([
        ['key' => 'subtitle', 'type' => 'string', 'value' => 'A subtitle'],
        ['key' => 'rating', 'type' => 'integer', 'value' => '5'],
        ['key' => 'featured', 'type' => 'boolean', 'value' => '1'],
        ['key' => 'internal_secret', 'type' => 'string', 'value' => 'do not leak'],
    ]);

    $response = $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts/meta-post');

    $response->assertOk()
        ->assertJsonPath('data.meta.subtitle', 'A subtitle')
        ->assertJsonPath('data.meta.rating', 5)
        ->assertJsonPath('data.meta.featured', true);

    expect($response->json('data.meta'))->not->toHaveKey('internal_secret');
});

it('serves no meta when nothing is allowlisted', function (): void {
    $post = makePost(['slug' => 'no-public-meta', 'title' => 'No public meta']);
    $post->meta()->create(['key' => 'anything', 'type' => 'string', 'value' => 'hidden']);

    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts/no-public-meta')
        ->assertOk()
        ->assertJsonPath('data.meta', []);
});

it('filters to featured posts and exposes the flag', function (): void {
    makePost(['slug' => 'sticky', 'title' => 'Sticky', 'is_featured' => true]);
    makePost(['slug' => 'plain', 'title' => 'Plain']);

    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts?featured=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.slug', 'sticky')
        ->assertJsonPath('data.0.featured', true);
});

it('sorts posts by view count descending', function (): void {
    // views is not mass-assignable (system-maintained), so set it directly.
    makePost(['slug' => 'cold', 'title' => 'Cold'])->forceFill(['views' => 3])->save();
    makePost(['slug' => 'hot', 'title' => 'Hot'])->forceFill(['views' => 99])->save();

    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts?sort=-views')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'hot')
        ->assertJsonPath('data.1.slug', 'cold');
});

it('buffers a view on show and reflects it after a flush', function (): void {
    makePost(['slug' => 'counted', 'title' => 'Counted']);

    $this->withToken(blogDeliveryToken())->getJson('/api/v1/blog/posts/counted')->assertOk();

    // Buffered, not yet written.
    expect(Post::query()->where('slug', 'counted')->value('views'))->toBe(0);

    app(ViewCounter::class)->flush();

    expect(Post::query()->where('slug', 'counted')->value('views'))->toBe(1);
});
