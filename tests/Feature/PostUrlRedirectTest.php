<?php

declare(strict_types=1);

/**
 * Redirects when a post's URL changes (blog 1.2.0, deferred v1 limitation D).
 *
 * The blog announces PublicUrlsMoved — old path, new path — whenever a
 * live, public post stops answering at the URL people may already have
 * shared: a slug or date edit, a category rename or re-file, a locale
 * change, a permalink pattern edit. Whichever plugin serves redirects
 * listens; the blog never names it.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Frontend\Events\PublicUrlsMoved;
use Magna\Frontend\UrlMove;
use Magna\Settings\LocalizationSettings;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Filament\Pages\BlogSettingsPage;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\PostUrlHistory;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

/** @param  array<string, mixed>  $attributes */
function movedPost(array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'Moving',
        'slug' => 'moving-'.bin2hex(random_bytes(4)),
        'locale' => 'en',
        'status' => PostStatus::Published,
        'visibility' => PostVisibility::Public,
        'published_at' => now()->setDate(2026, 3, 4)->startOfDay(),
    ], $attributes));
}

function useMovePattern(string $pattern): void
{
    $settings = BlogSettings::get();
    $settings->permalink_pattern = $pattern;
    $settings->save();
}

/** @return list<array{0: string, 1: string, 2: int}> */
function announcedMoves(): array
{
    return Event::dispatched(PublicUrlsMoved::class)
        ->flatMap(fn (array $call): array => $call[0]->moves)
        ->map(fn (UrlMove $move): array => [$move->from, $move->to, $move->status])
        ->values()
        ->all();
}

it('moves a live post when its slug changes', function (): void {
    $post = movedPost(['slug' => 'old-name']);
    Event::fake([PublicUrlsMoved::class]);

    $post->update(['slug' => 'new-name']);

    expect(announcedMoves())->toBe([['/blog/old-name', '/blog/new-name', 301]]);
});

it('moves a live post renamed by an autosave', function (): void {
    $post = movedPost(['slug' => 'old-name']);
    Event::fake([PublicUrlsMoved::class]);

    $post->autosaving = true;
    $post->update(['slug' => 'autosaved-name']);

    expect(announcedMoves())->toBe([['/blog/old-name', '/blog/autosaved-name', 301]]);
});

it('moves nothing for a post that was never public', function (Closure $attributes): void {
    $post = movedPost(array_merge(['slug' => 'old-name'], $attributes()));
    Event::fake([PublicUrlsMoved::class]);

    $post->update(['slug' => 'new-name']);

    expect(announcedMoves())->toBe([]);
})->with([
    'draft' => [fn (): array => ['status' => PostStatus::Draft]],
    'scheduled in the future' => [fn (): array => ['published_at' => now()->addDay()]],
    'private' => [fn (): array => ['visibility' => PostVisibility::Private]],
]);

it('moves nothing when the post leaves public view in the same edit', function (): void {
    $post = movedPost(['slug' => 'old-name']);
    Event::fake([PublicUrlsMoved::class]);

    $post->update(['slug' => 'new-name', 'status' => PostStatus::Draft]);

    expect(announcedMoves())->toBe([]);
});

it('moves a post whose date changes under a dated pattern', function (): void {
    useMovePattern('/news/{year}/{slug}');
    $post = movedPost(['slug' => 'dated']);
    Event::fake([PublicUrlsMoved::class]);

    $post->update(['published_at' => now()->setDate(2025, 6, 1)->startOfDay()]);

    expect(announcedMoves())->toBe([['/news/2026/dated', '/news/2025/dated', 301]]);
});

it('moves a post that changes category under a category pattern', function (): void {
    useMovePattern('/blog/{category}/{slug}');
    $news = Category::create(['name' => 'News', 'slug' => 'news']);
    $guides = Category::create(['name' => 'Guides', 'slug' => 'guides']);
    $post = movedPost(['slug' => 'filed', 'category_id' => $news->id]);
    Event::fake([PublicUrlsMoved::class]);

    $post->update(['category_id' => $guides->id]);

    expect(announcedMoves())->toBe([['/blog/news/filed', '/blog/guides/filed', 301]]);
});

it('moves a post whose locale changes', function (): void {
    $settings = LocalizationSettings::get();
    $settings->available_locales = ['en', 'fr'];
    $settings->fallback_locale = 'en';
    $settings->save();

    $post = movedPost(['slug' => 'bonjour']);
    Event::fake([PublicUrlsMoved::class]);

    $post->update(['locale' => 'fr']);

    expect(announcedMoves())->toBe([['/blog/bonjour', '/fr/blog/bonjour', 301]]);
});

it('moves every post in a category whose slug is renamed', function (): void {
    useMovePattern('/blog/{category}/{slug}');
    $category = Category::create(['name' => 'News', 'slug' => 'news']);
    movedPost(['slug' => 'one', 'category_id' => $category->id]);
    movedPost(['slug' => 'draft', 'category_id' => $category->id, 'status' => PostStatus::Draft]);
    Event::fake([PublicUrlsMoved::class]);

    $category->update(['slug' => 'updates']);

    expect(announcedMoves())->toBe([['/blog/news/one', '/blog/updates/one', 301]]);
});

it('moves nothing on a category rename the pattern does not read', function (): void {
    $category = Category::create(['name' => 'News', 'slug' => 'news']);
    movedPost(['slug' => 'one', 'category_id' => $category->id]);
    Event::fake([PublicUrlsMoved::class]);

    $category->update(['slug' => 'updates']);

    expect(announcedMoves())->toBe([]);
});

it('moves the posts a deleted category re-files under the fallback', function (): void {
    useMovePattern('/blog/{category}/{slug}');
    $category = Category::create(['name' => 'News', 'slug' => 'news']);
    movedPost(['slug' => 'refiled', 'category_id' => $category->id]);
    Event::fake([PublicUrlsMoved::class]);

    $category->delete();

    expect(announcedMoves())->toBe([['/blog/news/refiled', '/blog/uncategorised/refiled', 301]]);
});

it('moves every live post when the permalink pattern changes', function (): void {
    movedPost(['slug' => 'first']);
    movedPost(['slug' => 'hidden', 'status' => PostStatus::Draft]);

    $moves = app(PostUrlHistory::class)->movesForPattern('/blog/{slug}', '/news/{year}/{slug}');

    expect(array_map(fn (UrlMove $move): array => [$move->from, $move->to], $moves))
        ->toBe([['/blog/first', '/news/2026/first']]);
});

it('walks a large blog in chunks without missing a post', function (): void {
    foreach (range(1, 205) as $n) {
        movedPost(['slug' => 'bulk-'.$n]);
    }

    expect(app(PostUrlHistory::class)->movesForPattern('/blog/{slug}', '/posts/{slug}'))->toHaveCount(205);
});

it('announces the moves when the settings screen changes the pattern', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    $role = Role::create(['handle' => 'blog-moves-'.uniqid(), 'name' => 'Settings']);
    $role->grant('blog.settings.manage');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);

    movedPost(['slug' => 'first']);
    Event::fake([PublicUrlsMoved::class]);

    Livewire::test(BlogSettingsPage::class)
        ->set('data.permalink_pattern', '/articles/{slug}')
        ->call('save')
        ->assertHasNoErrors();

    expect(announcedMoves())->toBe([['/blog/first', '/articles/first', 301]]);
});

it('never reaches for a Pages class to record a redirect', function (): void {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && str_contains((string) file_get_contents($file->getPathname()), 'Magna\\Pages\\')) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
