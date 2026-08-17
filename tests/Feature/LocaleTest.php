<?php

declare(strict_types=1);

use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\BlogArchive;
use MagnaCms\Blog\Support\Locales;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

function localeToken(): string
{
    $user = User::factory()->create();
    $token = $user->createToken('t', ['delivery']);
    $token->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $token->plainTextToken;
}

function localePost(array $overrides): Post
{
    return Post::create(array_merge([
        'content' => ['blocks' => []], 'status' => 'published',
        'visibility' => 'public', 'published_at' => now()->subDay(),
    ], $overrides));
}

it('defaults a post to the configured default locale', function (): void {
    $post = localePost(['title' => 'Hi', 'slug' => 'hi']);

    expect($post->fresh()->locale)->toBe('en');
});

it('lists every world language with a display name', function (): void {
    $all = Locales::all();

    expect(count($all))->toBeGreaterThan(150)
        ->and($all)->toHaveKeys(['en', 'es', 'ar', 'zh', 'ja', 'hi', 'sw', 'cy'])
        ->and(Locales::name('en'))->toBe('English')
        ->and(Locales::name('zz'))->toBe('zz')          // unknown falls back to code
        ->and(Locales::has('fr'))->toBeTrue();
});

it('persists the blog default language setting', function (): void {
    $settings = BlogSettings::get();
    $settings->default_locale = 'ja';
    $settings->save();

    expect(BlogSettings::get()->default_locale)->toBe('ja');
});

it('filters the post index by locale', function (): void {
    localePost(['title' => 'English', 'slug' => 'english', 'locale' => 'en']);
    localePost(['title' => 'Espanol', 'slug' => 'espanol', 'locale' => 'es']);

    $this->withToken(localeToken())->getJson('/api/v1/blog/posts?locale=es')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.slug', 'espanol')
        ->assertJsonPath('data.0.locale', 'es');
});

it('exposes sibling translations sharing a translation group', function (): void {
    localePost(['title' => 'EN', 'slug' => 'post-en', 'locale' => 'en', 'translation_group' => 'grp-1']);
    localePost(['title' => 'ES', 'slug' => 'post-es', 'locale' => 'es', 'translation_group' => 'grp-1']);
    localePost(['title' => 'FR', 'slug' => 'post-fr', 'locale' => 'fr', 'translation_group' => 'grp-1']);

    $this->withToken(localeToken())->getJson('/api/v1/blog/posts/post-en')
        ->assertOk()
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonPath('data.translations', [
            ['locale' => 'es', 'slug' => 'post-es'],
            ['locale' => 'fr', 'slug' => 'post-fr'],
        ]);
});

it('returns no translations for an ungrouped post', function (): void {
    localePost(['title' => 'Solo', 'slug' => 'solo-locale', 'locale' => 'en']);

    $this->withToken(localeToken())->getJson('/api/v1/blog/posts/solo-locale')
        ->assertOk()
        ->assertJsonPath('data.translations', []);
});

it('round-trips locale and translation group through export and import', function (): void {
    localePost(['title' => 'DE', 'slug' => 'post-de', 'locale' => 'de', 'translation_group' => 'grp-x']);
    $archive = app(BlogArchive::class);

    $bundle = $archive->export();
    Post::query()->forceDelete();
    $archive->import($bundle);

    $post = Post::query()->where('slug', 'post-de')->firstOrFail();
    expect($post->locale)->toBe('de')
        ->and($post->translation_group)->toBe('grp-x');
});
