<?php

declare(strict_types=1);

use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Models\Post;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

function cacheToken(): string
{
    $user = User::factory()->create();
    $token = $user->createToken('t', ['delivery']);
    $token->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $token->plainTextToken;
}

it('returns an ETag and Cache-Control on the post list', function (): void {
    Post::create(['title' => 'Cached', 'slug' => 'cached', 'content' => ['blocks' => []],
        'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay()]);

    $response = $this->withToken(cacheToken())->getJson('/api/v1/blog/posts');

    $response->assertOk();
    expect($response->headers->get('ETag'))->not->toBeNull()
        ->and($response->headers->get('Cache-Control'))->toContain('max-age');
});

it('answers 304 when the client sends a matching If-None-Match', function (): void {
    Post::create(['title' => 'Fresh', 'slug' => 'fresh', 'content' => ['blocks' => []],
        'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay()]);

    $token = cacheToken();
    $etag = $this->withToken($token)->getJson('/api/v1/blog/posts/fresh')->headers->get('ETag');

    $this->withToken($token)
        ->withHeaders(['If-None-Match' => $etag])
        ->getJson('/api/v1/blog/posts/fresh')
        ->assertStatus(304);
});

it('sends a cacheable taxonomy response', function (): void {
    $response = $this->withToken(cacheToken())->getJson('/api/v1/blog/categories');

    $response->assertOk();
    expect($response->headers->get('ETag'))->not->toBeNull();
});
