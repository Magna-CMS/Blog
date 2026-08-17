<?php

declare(strict_types=1);

use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\BlogArchive;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

function coToken(): string
{
    $user = User::factory()->create();
    $token = $user->createToken('t', ['delivery']);
    $token->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $token->plainTextToken;
}

function coPost(User $author): Post
{
    return Post::create([
        'title' => 'Team post', 'slug' => 'team-post', 'content' => ['blocks' => []],
        'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay(),
        'author_id' => $author->id,
    ]);
}

it('exposes co-author names in the single-post payload', function (): void {
    $primary = User::factory()->create(['name' => 'Primary']);
    $co1 = User::factory()->create(['name' => 'Co One']);
    $co2 = User::factory()->create(['name' => 'Co Two']);

    $post = coPost($primary);
    $post->coAuthors()->sync([$co1->id, $co2->id]);

    $this->withToken(coToken())->getJson('/api/v1/blog/posts/team-post')
        ->assertOk()
        ->assertJsonPath('data.author', 'Primary')
        ->assertJsonPath('data.co_authors', ['Co One', 'Co Two']);
});

it('cascade-detaches co-authors when the post is force-deleted', function (): void {
    $primary = User::factory()->create();
    $co = User::factory()->create();
    $post = coPost($primary);
    $post->coAuthors()->sync([$co->id]);

    $post->forceDelete();

    expect(DB::table('blog_post_author')->count())->toBe(0);
});

it('round-trips co-authors by email through export and import', function (): void {
    $primary = User::factory()->create(['email' => 'primary@test.dev']);
    $co = User::factory()->create(['email' => 'co@test.dev']);
    $post = coPost($primary);
    $post->coAuthors()->sync([$co->id]);
    $archive = app(BlogArchive::class);

    $bundle = $archive->export();
    $post->coAuthors()->detach();

    $archive->import($bundle);

    $restored = Post::query()->where('slug', 'team-post')->firstOrFail();
    expect($restored->coAuthors->pluck('email')->all())->toBe(['co@test.dev']);
});
