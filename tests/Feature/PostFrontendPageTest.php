<?php

declare(strict_types=1);

/**
 * The public post page: a post served at its permalink, inside the site's
 * layout shell, by Magna Pages through the blog's frontend-page provider.
 *
 * Needs three things that ship separately — core's provider seam
 * (FrontendPageDataProvider, 1.6), a Pages release whose responder runs
 * providers, and the Pages dev plugin itself — so it skips, loudly, until
 * all three are present rather than passing over nothing.
 */

use Illuminate\Support\Facades\Log;
use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Frontend\FrontendPageDataProvider;
use Magna\Frontend\FrontendPageRegistry;
use Magna\Pages\Render\PageRenderer;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Frontend\PostFrontendPage;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;

uses(PluginTestCase::class);

beforeEach(function (): void {
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    if (! interface_exists(FrontendPageDataProvider::class)
        || (new ReflectionMethod(PageRenderer::class, 'renderFrontendPage'))->getNumberOfParameters() < 2) {
        test()->markTestSkipped('Needs core 1.6 frontend-page providers and a Pages responder that runs them.');
    }

    $this->enablePlugin('magna-cms/blog');
});

/** Re-read the permalink pattern into the mounted page, as the next request would. */
function postPagePattern(string $pattern): void
{
    $settings = BlogSettings::get();
    $settings->permalink_pattern = $pattern;
    $settings->save();

    app(FrontendPageRegistry::class)->register(PostFrontendPage::make());
}

function postPagePost(array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'Hello public world',
        'slug' => 'hello',
        'locale' => 'en',
        'excerpt' => 'Only the meta description says this.',
        'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body of the post']]]],
        'status' => PostStatus::Published,
        'visibility' => PostVisibility::Public,
        'published_at' => now()->subDay(),
    ], $attributes));
}

it('serves a published public post inside the layout shell', function (): void {
    postPagePost(['published_at' => now()->subDays(2)]);
    postPagePost(['title' => 'An older one', 'slug' => 'older', 'published_at' => now()->subDays(5)]);
    postPagePost(['title' => 'A newer one', 'slug' => 'newer', 'published_at' => now()->subDay()]);

    // A guest's post page is cached since 1.2.0: stored on the first visit,
    // served from the page cache on the next.
    $response = $this->get('/blog/hello')->assertOk()->assertHeader('X-Magna-Cache', 'miss');
    $this->get('/blog/hello')->assertOk()->assertHeader('X-Magna-Cache', 'hit');
    $html = (string) $response->getContent();

    expect($html)->toContain('magna-frontend-page--content')
        ->and($html)->toContain('<main')
        ->and($html)->toContain('<title>Hello public world')
        ->and($html)->toContain('Body of the post')
        // Rendered nowhere in the view: present only as the page's meta description.
        ->and($html)->toContain('Only the meta description says this.')
        ->and($html)->toContain('href="/blog/older"')
        ->and($html)->toContain('href="/blog/newer"');

    // The class hooks themes style this page by — they cannot override the view.
    expect($html)->toContain('<h1 class="post-title">')
        ->and($html)->toContain('<div class="post-meta">')
        ->and($html)->toContain('<div class="post-body">')
        ->and($html)->toContain('<nav class="post-nav"');
});

it('leaves out the prev/next nav when the post has no neighbours', function (): void {
    postPagePost();

    expect((string) $this->get('/blog/hello')->assertOk()->getContent())->not->toContain('post-nav');
});

it('never serves a post a visitor may not see', function (Closure $attributes): void {
    postPagePost($attributes());

    $this->get('/blog/hello')->assertNotFound();
})->with([
    'draft' => [fn (): array => ['status' => PostStatus::Draft, 'published_at' => null]],
    'scheduled' => [fn (): array => ['status' => PostStatus::Scheduled, 'published_at' => now()->addDay()]],
    'published, dated in the future' => [fn (): array => ['published_at' => now()->addDay()]],
    'private' => [fn (): array => ['visibility' => PostVisibility::Private]],
    'password protected' => [fn (): array => ['visibility' => PostVisibility::Password, 'password' => 'secret']],
]);

it('answers an unknown slug with the themed 404', function (): void {
    postPagePost();

    $html = (string) $this->get('/blog/no-such-post')->assertNotFound()->getContent();

    expect($html)->toContain('<title>Page not found</title>');
});

it('serves posts at a custom pattern, and only there', function (): void {
    postPagePattern('/posts/{slug}');
    postPagePost();

    $this->get('/posts/hello')->assertOk();
    $this->get('/blog/hello')->assertNotFound();
});

it('falls back to /blog/{slug}, and says so, when the pattern has no slug', function (): void {
    Log::spy();
    postPagePattern('/blog/{year}');
    postPagePost();

    $this->get('/blog/hello')->assertOk();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'permalink pattern'));
});

it('serves a multi-token pattern only at the canonical path', function (): void {
    postPagePattern('/news/{year}/{category}/{slug}');
    $category = Category::create(['name' => 'Releases', 'slug' => 'releases']);
    postPagePost(['category_id' => $category->id, 'published_at' => now()->setDate(2026, 1, 9)]);

    $this->get('/news/2026/releases/hello')->assertOk();

    // Same post, wrong year or category: an alias, not a second page.
    $this->get('/news/2025/releases/hello')->assertNotFound();
    $this->get('/news/2026/uncategorised/hello')->assertNotFound();
});

it('lets a published page at the same path win over the post', function (): void {
    postPagePattern('/{slug}');
    postPagePost();

    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $author = User::factory()->create();
    $author->assignRole($role);

    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => 'Owner page', 'slug' => 'hello',
        'blocks_data' => [[
            'id' => 'sec-bp', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-bp', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-bp', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Owner content']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);

    $html = (string) $this->get('/hello')->assertOk()->getContent();

    expect($html)->toContain('Owner content')
        ->and($html)->not->toContain('Body of the post');
});
