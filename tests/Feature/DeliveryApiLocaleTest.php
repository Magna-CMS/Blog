<?php

declare(strict_types=1);

/*
 * The post pages learned locales in 1.2.0; the delivery API did not. Its
 * prev/next spanned every language — a French post's "next" could be an
 * English one — its translations named a slug but not where the page
 * lives, and an API client had no way to obtain the signed stamp the web
 * form's bot check uses.
 */

use Magna\Frontend\Forms\SignedFormTimestamp;
use Magna\Settings\LocalizationSettings;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Models\Comment;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\CommentSubmissionHandler;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');

    $settings = LocalizationSettings::get();
    $settings->available_locales = ['en', 'fr'];
    $settings->fallback_locale = 'en';
    $settings->save();
});

function apiLocaleToken(): string
{
    $user = User::factory()->create();
    $token = $user->createToken('t', ['delivery']);
    $token->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $token->plainTextToken;
}

function apiLocalePost(array $overrides): Post
{
    return Post::create(array_merge([
        'content' => ['blocks' => []], 'status' => 'published',
        'visibility' => 'public', 'published_at' => now()->subDay(), 'allow_comments' => true,
    ], $overrides));
}

it('keeps a post\'s prev and next in its own language', function (): void {
    apiLocalePost(['title' => 'FR old', 'slug' => 'fr-old', 'locale' => 'fr', 'published_at' => now()->subDays(5)]);
    apiLocalePost(['title' => 'EN between', 'slug' => 'en-between', 'locale' => 'en', 'published_at' => now()->subDays(4)]);
    apiLocalePost(['title' => 'FR mid', 'slug' => 'fr-mid', 'locale' => 'fr', 'published_at' => now()->subDays(3)]);
    apiLocalePost(['title' => 'EN after', 'slug' => 'en-after', 'locale' => 'en', 'published_at' => now()->subDays(2)]);
    apiLocalePost(['title' => 'FR new', 'slug' => 'fr-new', 'locale' => 'fr', 'published_at' => now()->subDay()]);

    $this->withToken(apiLocaleToken())->getJson('/api/v1/blog/posts/fr-mid')
        ->assertOk()
        ->assertJsonPath('data.adjacent.prev.slug', 'fr-old')
        ->assertJsonPath('data.adjacent.prev.url', '/fr/blog/fr-old')
        ->assertJsonPath('data.adjacent.next.slug', 'fr-new')
        ->assertJsonPath('data.adjacent.next.url', '/fr/blog/fr-new');

    $this->withToken(apiLocaleToken())->getJson('/api/v1/blog/posts/en-between')
        ->assertOk()
        ->assertJsonPath('data.adjacent.prev', null)
        ->assertJsonPath('data.adjacent.next.slug', 'en-after');
});

it('gives every translation the url it is served at', function (): void {
    apiLocalePost(['title' => 'EN', 'slug' => 'post-en', 'locale' => 'en', 'translation_group' => 'grp-1']);
    apiLocalePost(['title' => 'ES', 'slug' => 'post-es', 'locale' => 'es', 'translation_group' => 'grp-1']);
    apiLocalePost(['title' => 'FR', 'slug' => 'post-fr', 'locale' => 'fr', 'translation_group' => 'grp-1']);

    // Spanish is not a language this site serves, so it lives at the bare path.
    $this->withToken(apiLocaleToken())->getJson('/api/v1/blog/posts/post-en')
        ->assertOk()
        ->assertJsonPath('data.translations', [
            ['locale' => 'es', 'slug' => 'post-es', 'url' => '/blog/post-es'],
            ['locale' => 'fr', 'slug' => 'post-fr', 'url' => '/fr/blog/post-fr'],
        ]);
});

it('hands an api client a signed comment stamp that is never cached', function (): void {
    $post = apiLocalePost(['title' => 'Stamp', 'slug' => 'stamp', 'locale' => 'en']);
    $this->freezeTime();

    $response = $this->withToken(apiLocaleToken())
        ->getJson('/api/v1/blog/posts/stamp/comments/stamp')
        ->assertOk();

    $stamp = $response->json('_ts');

    expect($stamp)->toBeString()
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(SignedFormTimestamp::passes($stamp, CommentSubmissionHandler::PURPOSE, 0))->toBeTrue()
        ->and($post->exists)->toBeTrue();
});

it('refuses a stamp request for a post that cannot be commented on', function (): void {
    apiLocalePost(['title' => 'Draft', 'slug' => 'draft-stamp', 'status' => 'draft']);

    $this->withToken(apiLocaleToken())
        ->getJson('/api/v1/blog/posts/draft-stamp/comments/stamp')
        ->assertNotFound();
});

it('accepts an api comment carrying the stamp once a person could have typed it', function (): void {
    apiLocalePost(['title' => 'Talk', 'slug' => 'talk', 'locale' => 'en']);
    $token = apiLocaleToken();
    $this->freezeTime();

    $stamp = $this->withToken($token)->getJson('/api/v1/blog/posts/talk/comments/stamp')->json('_ts');
    $payload = ['author_name' => 'Asha', 'author_email' => 'asha@example.com', 'body' => 'Useful post.', '_ts' => $stamp, 'website' => ''];

    // Instantly: a bot. Quietly not stored, as the API always answered.
    $this->withToken($token)->postJson('/api/v1/blog/posts/talk/comments', $payload);
    expect(Comment::query()->count())->toBe(0);

    $this->travel(3)->seconds();
    $this->withToken($token)->postJson('/api/v1/blog/posts/talk/comments', $payload)->assertSuccessful();

    expect(Comment::query()->count())->toBe(1);
});
