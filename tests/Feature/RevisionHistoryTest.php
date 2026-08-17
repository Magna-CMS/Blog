<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Magna\Testing\PluginTestCase;
use Magna\Webhooks\WebhookDelivery;
use Magna\Webhooks\WebhookSubscription;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

/** Re-load the post and apply a mutation, optionally flagged as a background autosave. */
function persistPost(int $id, array $attributes, bool $autosaving = false): void
{
    $post = Post::findOrFail($id);
    $post->autosaving = $autosaving;
    $post->fill($attributes);
    $post->save();
}

it('does not flood revision history with autosaves', function (): void {
    $post = Post::create(['title' => 'Draft', 'slug' => 'rev-flood']);

    // The create itself records one baseline revision.
    expect($post->revisions()->count())->toBe(1);

    // 30 autosaves that each change the content must add no history.
    for ($i = 1; $i <= 30; $i++) {
        persistPost($post->id, ['title' => "Autosaved v{$i}"], autosaving: true);
    }

    expect($post->fresh()->revisions()->count())->toBe(1)
        // The draft row itself still holds the latest autosaved content.
        ->and($post->fresh()->title)->toBe('Autosaved v30');
});

it('records a revision on an explicit (non-autosave) save', function (): void {
    $post = Post::create(['title' => 'Draft', 'slug' => 'rev-explicit']);

    persistPost($post->id, ['title' => 'Meaningful edit'], autosaving: false);

    expect($post->fresh()->revisions()->count())->toBe(2)
        ->and($post->fresh()->revisions()->first()->payload['title'])->toBe('Meaningful edit');
});

it('does not record a duplicate revision when the snapshot is unchanged', function (): void {
    $post = Post::create(['title' => 'Draft', 'slug' => 'rev-dedup']);
    expect($post->revisions()->count())->toBe(1);

    // is_featured is not part of the revision snapshot, so this fires `updated`
    // but produces a snapshot identical to the latest revision — deduped away.
    persistPost($post->id, ['is_featured' => true], autosaving: false);

    expect($post->fresh()->revisions()->count())->toBe(1);
});

it('retains multiple meaningful revisions and still prunes to the cap', function (): void {
    $settings = BlogSettings::get();
    $settings->max_revisions = 3;
    $settings->save();

    $post = Post::create(['title' => 'v0', 'slug' => 'rev-prune']); // baseline = 1

    foreach (['v1', 'v2', 'v3', 'v4'] as $title) {
        persistPost($post->id, ['title' => $title], autosaving: false);
    }

    // Six distinct snapshots created, pruned down to the configured maximum.
    expect($post->fresh()->revisions()->count())->toBe(3)
        ->and($post->fresh()->revisions()->first()->payload['title'])->toBe('v4');
});

it('does not fire the blog.post.updated webhook for autosaves, only meaningful saves', function (): void {
    Queue::fake();

    WebhookSubscription::create([
        'url' => 'https://example.test/hook',
        'secret' => 'shh',
        'events' => ['blog.post.updated'],
        'active' => true,
    ]);

    $post = Post::create(['title' => 'Hooked', 'slug' => 'rev-webhook', 'status' => 'draft']);

    // Autosave: no webhook.
    persistPost($post->id, ['title' => 'Autosaved'], autosaving: true);
    expect(WebhookDelivery::query()->where('event', 'blog.post.updated')->count())->toBe(0);

    // Explicit save: exactly one webhook.
    persistPost($post->id, ['title' => 'Saved'], autosaving: false);
    expect(WebhookDelivery::query()->where('event', 'blog.post.updated')->count())->toBe(1);
});
