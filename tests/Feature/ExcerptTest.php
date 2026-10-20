<?php

declare(strict_types=1);

/**
 * Excerpts (blog 1.2.0, deferred v1 limitation F).
 *
 * A post without a hand-written excerpt still has a summary: the first
 * paragraph of its body, up to 160 characters, cut at a word boundary and
 * stored as generated_excerpt on every save. Post::summary() prefers the
 * author's excerpt, and the listing, meta description, SEO and the delivery
 * API's additive `summary` field all read it.
 */

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Frontend\RenderContext;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Blocks\DataSources\PostsSource;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Filament\Resources\PostResource\Pages\CreatePost;
use MagnaCms\Blog\Frontend\PostPageProvider;
use MagnaCms\Blog\Http\Resources\PostTransformer;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\ExcerptGenerator;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

/** The panel routes the builder view links to, as PostOwnershipTest stubs them. */
function builderRoutes(): void
{
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Route::get('/_test/posts', fn () => '')->name('filament.magna.resources.posts.index');
    Route::get('/_test/posts/create', fn () => '')->name('filament.magna.resources.posts.create');
    Route::get('/_test/posts/{record}/edit', fn () => '')->name('filament.magna.resources.posts.edit');
}

/** @param  list<array<string, mixed>>  $blocks */
function blocksOf(array $blocks): array
{
    return ['blocks' => $blocks];
}

/** @param  array<string, mixed>  $attributes */
function excerptPost(array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'Summarised',
        'slug' => 'summarised-'.bin2hex(random_bytes(4)),
        'locale' => 'en',
        'content' => blocksOf([
            ['type' => 'header', 'data' => ['text' => 'A heading is not a summary', 'level' => 2]],
            ['type' => 'paragraph', 'data' => ['text' => 'The <b>first</b> paragraph &amp; its words.']],
            ['type' => 'paragraph', 'data' => ['text' => 'The second paragraph.']],
        ]),
        'status' => PostStatus::Published,
        'visibility' => PostVisibility::Public,
        'published_at' => now()->subDay(),
    ], $attributes));
}

it('takes the first paragraph of text, as text', function (): void {
    expect(ExcerptGenerator::fromContent(excerptPost()->content))->toBe('The first paragraph & its words.');
});

it('cuts a long paragraph at a word boundary, within 160 characters', function (): void {
    $text = trim(str_repeat('wordy ', 60));

    $excerpt = (string) ExcerptGenerator::fromContent(blocksOf([['type' => 'paragraph', 'data' => ['text' => $text]]]));

    expect(mb_strlen($excerpt))->toBeLessThanOrEqual(160)
        ->and($excerpt)->toEndWith('wordy…')
        ->and(mb_substr($excerpt, 0, -1))->toBe(mb_substr($text, 0, mb_strlen($excerpt) - 1));
});

it('has nothing to say about a post with no paragraph text', function (mixed $content): void {
    expect(ExcerptGenerator::fromContent($content))->toBeNull();
})->with([
    'null' => [null],
    'no blocks' => [['blocks' => []]],
    'only empty paragraphs' => [blocksOf([['type' => 'paragraph', 'data' => ['text' => '<br>']]])],
    'only a heading' => [blocksOf([['type' => 'header', 'data' => ['text' => 'Title']]])],
]);

it('stores the generated excerpt on every save', function (): void {
    $post = excerptPost();
    expect($post->generated_excerpt)->toBe('The first paragraph & its words.');

    $post->update(['content' => blocksOf([['type' => 'paragraph', 'data' => ['text' => 'Rewritten.']]])]);
    expect($post->fresh()?->generated_excerpt)->toBe('Rewritten.');
});

it('prefers the author\'s excerpt over the generated one', function (): void {
    expect(excerptPost(['excerpt' => 'Hand written.'])->summary())->toBe('Hand written.')
        ->and(excerptPost(['excerpt' => '   '])->summary())->toBe('The first paragraph & its words.')
        ->and(excerptPost(['content' => null])->summary())->toBeNull();
});

it('describes a listing item, the post page and the API with the summary', function (): void {
    $post = excerptPost(['slug' => 'summary-everywhere']);

    app(RenderContext::class)->setRequest(Request::create('/blog/summary-everywhere'));
    $page = app(PostPageProvider::class)->resolve(['slug' => 'summary-everywhere']);
    $api = app(PostTransformer::class)->summary($post->load(['author', 'category']));

    expect((new PostsSource)->fetch([])[0]['description'])->toBe('The first paragraph & its words.')
        ->and($page?->metaDescription)->toBe('The first paragraph & its words.')
        ->and($api['summary'])->toBe('The first paragraph & its words.')
        // Additive: the stored excerpt keeps its own field, untouched.
        ->and($api)->toHaveKey('excerpt')
        ->and($api['excerpt'])->toBeNull();
});

it('backfills posts that predate the column', function (): void {
    $post = excerptPost();
    $migration = require __DIR__.'/../../database/migrations/2026_10_20_000001_add_generated_excerpt_to_blog_posts.php';

    $migration->down();
    $migration->up();

    expect(DB::table('blog_posts')->where('id', $post->id)->value('generated_excerpt'))
        ->toBe('The first paragraph & its words.');
});

it('requires an excerpt in the builder when the site says excerpts may not be empty', function (): void {
    $settings = BlogSettings::get();
    $settings->allow_empty_excerpt = false;
    $settings->save();

    builderRoutes();

    $role = Role::create(['handle' => 'excerpt-'.uniqid(), 'name' => 'Writer']);
    $role->grant('blog.posts.view', 'blog.posts.create', 'blog.posts.edit', 'blog.posts.publish');
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);

    Livewire::test(CreatePost::class)
        ->set('data.title', 'No summary')
        ->call('saveDraft')
        ->assertHasErrors(['data.excerpt']);

    Livewire::test(CreatePost::class)
        ->set('data.title', 'With summary')
        ->set('data.excerpt', 'A short summary.')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(Post::query()->where('title', 'With summary')->value('excerpt'))->toBe('A short summary.');
});

it('limits the excerpt the builder accepts to 300 characters', function (): void {
    builderRoutes();

    $role = Role::create(['handle' => 'excerpt-'.uniqid(), 'name' => 'Writer']);
    $role->grant('blog.posts.view', 'blog.posts.create', 'blog.posts.edit');
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);

    Livewire::test(CreatePost::class)
        ->set('data.title', 'Too long')
        ->set('data.excerpt', str_repeat('x', 301))
        ->call('saveDraft')
        ->assertHasErrors(['data.excerpt']);
});
