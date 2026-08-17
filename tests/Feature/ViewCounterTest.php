<?php

declare(strict_types=1);

use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\ViewCounter;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

function counterPost(): Post
{
    return Post::create([
        'title' => 'Counted host',
        'slug' => 'counted-host',
        'content' => ['blocks' => []],
        'status' => 'published',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
    ]);
}

it('counts distinct viewers once per IP per day and flushes to the database', function (): void {
    $post = counterPost();
    $counter = app(ViewCounter::class);

    expect($counter->record($post, '10.0.0.1'))->toBeTrue()   // first view
        ->and($counter->record($post, '10.0.0.1'))->toBeFalse() // same IP, deduped
        ->and($counter->record($post, '10.0.0.2'))->toBeTrue(); // distinct IP

    // Nothing written until the flush runs.
    expect($post->fresh()->views)->toBe(0);

    expect($counter->flush())->toBe(1);
    expect($post->fresh()->views)->toBe(2);
});

it('is a no-op flush when there is nothing buffered', function (): void {
    $counter = app(ViewCounter::class);

    expect($counter->flush())->toBe(0);
});

it('does not double-apply a buffer across two flushes', function (): void {
    $post = counterPost();
    $counter = app(ViewCounter::class);

    $counter->record($post, '10.0.0.1');
    $counter->flush();
    $counter->flush();

    expect($post->fresh()->views)->toBe(1);
});
