<?php

declare(strict_types=1);

use Magna\Auth\PermissionRegistry;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Models\Series;
use MagnaCms\Blog\Support\BlogArchive;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

function seriesToken(): string
{
    $user = User::factory()->create();
    $token = $user->createToken('t', ['delivery']);
    $token->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $token->plainTextToken;
}

function seriesPost(Series $series, int $position, string $slug): Post
{
    return Post::create([
        'title' => 'Part '.$position,
        'slug' => $slug,
        'content' => ['blocks' => []],
        'status' => 'published',
        'visibility' => 'public',
        'published_at' => now()->subDays(10 - $position),
        'series_id' => $series->id,
        'series_position' => $position,
    ]);
}

it('registers the series management permission', function (): void {
    expect(app(PermissionRegistry::class)->has('blog.series.manage'))->toBeTrue();
});

it('counts the parts (posts) in a series', function (): void {
    $series = Series::create(['title' => 'Counted', 'slug' => 'counted']);
    seriesPost($series, 1, 'c-1');
    seriesPost($series, 2, 'c-2');

    expect($series->loadCount('posts')->posts_count)->toBe(2);
});

it('filters posts by series slug, ordered by position', function (): void {
    $series = Series::create(['title' => 'Laravel Deep Dive', 'slug' => 'laravel-deep-dive']);
    seriesPost($series, 2, 'part-two');
    seriesPost($series, 1, 'part-one');
    Post::create(['title' => 'Loose', 'slug' => 'loose', 'content' => ['blocks' => []], 'status' => 'published', 'visibility' => 'public', 'published_at' => now()]);

    $response = $this->withToken(seriesToken())->getJson('/api/v1/blog/posts?series=laravel-deep-dive');

    $response->assertOk()->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.slug', 'part-one')
        ->assertJsonPath('data.1.slug', 'part-two');
});

it('exposes part-of navigation with prev/next in the single-post payload', function (): void {
    $series = Series::create(['title' => 'Trilogy', 'slug' => 'trilogy']);
    seriesPost($series, 1, 's-one');
    seriesPost($series, 2, 's-two');
    seriesPost($series, 3, 's-three');

    $this->withToken(seriesToken())->getJson('/api/v1/blog/posts/s-two')
        ->assertOk()
        ->assertJsonPath('data.series.title', 'Trilogy')
        ->assertJsonPath('data.series.position', 2)
        ->assertJsonPath('data.series.total', 3)
        ->assertJsonPath('data.series.prev.slug', 's-one')
        ->assertJsonPath('data.series.next.slug', 's-three');
});

it('returns null series for a post not in any series', function (): void {
    Post::create(['title' => 'Solo', 'slug' => 'solo', 'content' => ['blocks' => []], 'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay()]);

    $this->withToken(seriesToken())->getJson('/api/v1/blog/posts/solo')
        ->assertOk()
        ->assertJsonPath('data.series', null);
});

it('nulls a post series when the series is deleted', function (): void {
    $series = Series::create(['title' => 'Temp', 'slug' => 'temp']);
    $post = seriesPost($series, 1, 'temp-1');

    $series->delete();

    expect($post->fresh()->series_id)->toBeNull();
});

it('round-trips series through export and import', function (): void {
    $series = Series::create(['title' => 'Guide', 'slug' => 'guide', 'description' => 'A guide']);
    seriesPost($series, 1, 'g-one');
    $archive = app(BlogArchive::class);

    $bundle = $archive->export();
    Post::query()->forceDelete();
    Series::query()->delete();

    $archive->import($bundle);

    $post = Post::query()->where('slug', 'g-one')->firstOrFail();
    expect($post->series->slug)->toBe('guide')
        ->and($post->series_position)->toBe(1)
        ->and(Series::query()->count())->toBe(1);
});
