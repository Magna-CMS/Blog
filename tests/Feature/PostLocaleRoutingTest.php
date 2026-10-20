<?php

declare(strict_types=1);

/**
 * Locale-prefixed post URLs (blog 1.2.0, deferred v1 limitation B).
 *
 * The site's fallback locale lives at bare paths and every other available
 * locale under its prefix — core's LocaleUrls rule. A post is served in its
 * own locale when that locale is a prefixed one, and at the bare path
 * otherwise; every link the blog emits says so, and the post page answers
 * only in the locale the post is served in.
 */

use Illuminate\Http\Request;
use Magna\Frontend\RenderContext;
use Magna\Settings\LocalizationSettings;
use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Blocks\DataSources\PostsSource;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Frontend\PostPageProvider;
use MagnaCms\Blog\Http\Resources\PostTransformer;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\PostPermalink;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');

    $settings = LocalizationSettings::get();
    $settings->available_locales = ['en', 'fr'];
    $settings->fallback_locale = 'en';
    $settings->save();
});

/** @param  array<string, mixed>  $attributes */
function routedPost(array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'A post',
        'slug' => 'locale-'.bin2hex(random_bytes(4)),
        'locale' => 'en',
        'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]]],
        'status' => PostStatus::Published,
        'visibility' => PostVisibility::Public,
        'published_at' => now()->subDay(),
    ], $attributes));
}

/** The post page provider as a request in $locale would run it. */
function providerIn(?string $locale, string $uri = '/'): PostPageProvider
{
    app(RenderContext::class)->setRequest(Request::create($uri), $locale);

    return app(PostPageProvider::class);
}

it('serves the fallback locale its own posts and those in no prefixed locale', function (): void {
    routedPost(['title' => 'English']);
    routedPost(['title' => 'German, not a site locale', 'locale' => 'de']);
    routedPost(['title' => 'Français', 'locale' => 'fr']);

    $titles = fn (string $locale): array => Post::query()->servedIn($locale)->orderBy('title')->pluck('title')->all();

    expect($titles('en'))->toBe(['English', 'German, not a site locale'])
        ->and($titles('fr'))->toBe(['Français']);
});

it('prefixes a post url with its locale only when that locale is prefixed', function (): void {
    $permalink = PostPermalink::fromSettings();

    expect($permalink->url(routedPost(['slug' => 'hello'])))->toBe('/blog/hello')
        ->and($permalink->url(routedPost(['slug' => 'bonjour', 'locale' => 'fr'])))->toBe('/fr/blog/bonjour')
        ->and($permalink->url(routedPost(['slug' => 'hallo', 'locale' => 'de'])))->toBe('/blog/hallo');
});

it('emits prefixed links from the posts data source', function (): void {
    routedPost(['slug' => 'bonjour', 'locale' => 'fr']);

    expect((new PostsSource)->fetch(['locale' => 'fr'])[0]['url'])->toBe('/fr/blog/bonjour');
});

it('emits prefixed links from the delivery API, additively', function (): void {
    $post = routedPost(['slug' => 'bonjour', 'locale' => 'fr'])->load(['author', 'category']);

    $summary = app(PostTransformer::class)->summary($post);

    expect($summary['url'])->toBe('/fr/blog/bonjour')
        ->and($summary['slug'])->toBe('bonjour');
});

it('answers a post page only in the locale the post is served in', function (): void {
    routedPost(['slug' => 'bonjour', 'locale' => 'fr']);
    routedPost(['slug' => 'hello']);

    expect(providerIn('fr', '/fr/blog/bonjour')->resolve(['slug' => 'bonjour']))->not->toBeNull()
        // The French post at its bare path, and the English one under /fr.
        ->and(providerIn(null, '/blog/bonjour')->resolve(['slug' => 'bonjour']))->toBeNull()
        ->and(providerIn('fr', '/fr/blog/hello')->resolve(['slug' => 'hello']))->toBeNull()
        ->and(providerIn(null, '/blog/hello')->resolve(['slug' => 'hello']))->not->toBeNull();
});

it('links prev and next only to posts in the same locale', function (): void {
    routedPost(['slug' => 'older', 'locale' => 'fr', 'title' => 'Plus ancien', 'published_at' => now()->subDays(5)]);
    routedPost(['slug' => 'english-between', 'published_at' => now()->subDays(3)]);
    routedPost(['slug' => 'bonjour', 'locale' => 'fr', 'published_at' => now()->subDay()]);

    $result = providerIn('fr', '/fr/blog/bonjour')->resolve(['slug' => 'bonjour']);

    expect($result?->data['previous'])->toBe(['title' => 'Plus ancien', 'url' => '/fr/blog/older'])
        ->and($result?->data['next'])->toBeNull();
});

it('carries the translations of a post as locale alternates', function (): void {
    routedPost(['slug' => 'hello', 'translation_group' => 'greeting']);
    routedPost(['slug' => 'bonjour', 'locale' => 'fr', 'translation_group' => 'greeting']);
    // Not live: never offered as an alternate.
    routedPost(['slug' => 'hallo', 'locale' => 'de', 'translation_group' => 'greeting', 'status' => PostStatus::Draft]);

    $result = providerIn(null, '/blog/hello')->resolve(['slug' => 'hello']);

    expect($result?->localeAlternates)->toBe(['en' => '/blog/hello', 'fr' => '/fr/blog/bonjour']);
});

it('has no alternates for a post without translations', function (): void {
    routedPost(['slug' => 'hello']);

    expect(providerIn(null, '/blog/hello')->resolve(['slug' => 'hello'])?->localeAlternates)->toBe([]);
});
