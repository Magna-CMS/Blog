<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Enums\MetaType;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Models\PostMeta;
use MagnaCms\Blog\Support\MetaRegistry;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

function metaPost(): Post
{
    return Post::create([
        'title' => 'Meta host',
        'slug' => 'meta-host',
        'content' => ['blocks' => []],
        'status' => 'published',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
    ]);
}

it('coerces stored values to their declared type via metaValue', function (): void {
    $post = metaPost();
    $post->meta()->createMany([
        ['key' => 'title_tag', 'type' => 'string', 'value' => 'Hello'],
        ['key' => 'count', 'type' => 'integer', 'value' => '42'],
        ['key' => 'is_cornerstone', 'type' => 'boolean', 'value' => 'true'],
        ['key' => 'config', 'type' => 'json', 'value' => ['a' => 1, 'b' => [2, 3]]],
    ]);
    $post->load('meta');

    expect($post->metaValue('title_tag'))->toBe('Hello')
        ->and($post->metaValue('count'))->toBe(42)
        ->and($post->metaValue('is_cornerstone'))->toBeTrue()
        ->and($post->metaValue('config'))->toBe(['a' => 1, 'b' => [2, 3]])
        ->and($post->metaValue('missing', 'fallback'))->toBe('fallback');
});

it('coerces a date meta value to an ISO 8601 string', function (): void {
    $meta = new PostMeta(['key' => 'event_at', 'type' => MetaType::Date->value, 'value' => '2025-03-01']);

    expect($meta->typedValue())->toStartWith('2025-03-01T');
});

it('enforces one row per key with a unique constraint', function (): void {
    $post = metaPost();
    $post->meta()->create(['key' => 'dupe', 'type' => 'string', 'value' => 'first']);

    expect(fn () => $post->meta()->create(['key' => 'dupe', 'type' => 'string', 'value' => 'second']))
        ->toThrow(QueryException::class);
});

it('cascade-deletes meta when its post is force-deleted', function (): void {
    $post = metaPost();
    $post->meta()->create(['key' => 'gone', 'type' => 'string', 'value' => 'bye']);

    $post->forceDelete();

    expect(PostMeta::query()->count())->toBe(0);
});

it('unions registry public keys into the exposed set', function (): void {
    $registry = app(MetaRegistry::class);
    $registry->define('seo_title', 'SEO title', MetaType::String, public: true);
    $registry->define('seo_internal', 'Internal', MetaType::String, public: false);

    expect($registry->publicKeys())->toContain('seo_title')->not->toContain('seo_internal');
});
