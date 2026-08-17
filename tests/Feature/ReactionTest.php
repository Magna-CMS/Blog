<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Models\Reaction;
use MagnaCms\Blog\Support\ReactionService;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

function reactableToken(): string
{
    $user = User::factory()->create();
    $token = $user->createToken('t', ['delivery']);
    $token->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $token->plainTextToken;
}

function reactablePost(): Post
{
    return Post::create([
        'title' => 'React', 'slug' => 'react-me', 'content' => ['blocks' => []],
        'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay(),
    ]);
}

it('toggles a reaction on and off for the same visitor', function (): void {
    $post = reactablePost();
    $service = app(ReactionService::class);
    $fp = $service->fingerprint('10.0.0.1', 'agent');

    expect($service->toggle($post, 'like', $fp))->toMatchArray(['type' => 'like', 'count' => 1, 'reacted' => true])
        ->and($service->toggle($post, 'like', $fp))->toMatchArray(['count' => 0, 'reacted' => false]);
});

it('does not error when a concurrent request inserts the same reaction first', function (): void {
    $post = reactablePost();
    $service = app(ReactionService::class);
    $fp = $service->fingerprint('10.0.0.9', 'agent');

    // Simulate the race: just before the service's INSERT lands, a "concurrent"
    // request inserts the identical (post, type, fingerprint) row. The unique
    // index then rejects the service's own insert — it must recover to a clean
    // "reacted" result rather than surfacing a 500.
    Reaction::creating(function (Reaction $reaction): void {
        DB::table('blog_reactions')->insertOrIgnore([
            'post_id' => $reaction->post_id,
            'type' => $reaction->type,
            'fingerprint' => $reaction->fingerprint,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $result = $service->toggle($post, 'like', $fp);

    expect($result)->toMatchArray(['type' => 'like', 'reacted' => true, 'count' => 1]);
});

it('counts distinct visitors and zero-fills configured types', function (): void {
    $post = reactablePost();
    $service = app(ReactionService::class);

    $service->toggle($post, 'like', $service->fingerprint('10.0.0.1', 'a'));
    $service->toggle($post, 'like', $service->fingerprint('10.0.0.2', 'b'));
    $service->toggle($post, 'love', $service->fingerprint('10.0.0.1', 'a'));

    expect($service->counts($post->fresh()))
        ->toBe(['like' => 2, 'love' => 1, 'clap' => 0, 'insightful' => 0]);
});

it('toggles a reaction through the delivery endpoint', function (): void {
    reactablePost();

    $this->withToken(reactableToken())
        ->postJson('/api/v1/blog/posts/react-me/reactions', ['type' => 'like'])
        ->assertOk()
        ->assertJsonPath('data.type', 'like')
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.reacted', true);
});

it('rejects an unknown reaction type', function (): void {
    reactablePost();

    $this->withToken(reactableToken())
        ->postJson('/api/v1/blog/posts/react-me/reactions', ['type' => 'rage'])
        ->assertStatus(422);
});

it('exposes reaction counts in the single-post payload', function (): void {
    $post = reactablePost();
    app(ReactionService::class)->toggle($post, 'like', 'fp-abc');

    $this->withToken(reactableToken())
        ->getJson('/api/v1/blog/posts/react-me')
        ->assertOk()
        ->assertJsonPath('data.reactions.like', 1)
        ->assertJsonPath('data.reactions.clap', 0);
});

it('cascade-deletes reactions when the post is force-deleted', function (): void {
    $post = reactablePost();
    app(ReactionService::class)->toggle($post, 'like', 'fp-xyz');

    $post->forceDelete();

    expect(Reaction::query()->count())->toBe(0);
});
