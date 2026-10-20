<?php

declare(strict_types=1);

/**
 * Caching post pages, and purging them (blog 1.2.0, deferred v1 limitation C).
 *
 * A post page opts into the site's page cache under `blog:post:{id}` and
 * `blog:posts`, and says when it goes stale on its own (the next post going
 * live, the comment form closing). Every write that changes what a visitor
 * sees announces PublicContentChanged with the matching tags, so whichever
 * plugin caches public responses purges them; nothing per-visitor is ever
 * offered to the cache.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Frontend\Events\PublicContentChanged;
use Magna\Frontend\FrontendPageResult;
use Magna\Frontend\RenderContext;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Enums\CommentStatus;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Filament\Pages\BlogSettingsPage;
use MagnaCms\Blog\Frontend\PostPageProvider;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Comment;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Models\Tag;
use MagnaCms\Blog\Settings\BlogSettings;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

/** @param  array<string, mixed>  $attributes */
function cachedPost(array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'Cached',
        'slug' => 'cached-'.bin2hex(random_bytes(4)),
        'locale' => 'en',
        'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]]],
        'status' => PostStatus::Published,
        'visibility' => PostVisibility::Public,
        'published_at' => now()->subDay(),
    ], $attributes));
}

function cachedPageFor(Post $post): ?FrontendPageResult
{
    $request = request();
    app(RenderContext::class)->setRequest($request);

    return app(PostPageProvider::class)->resolve(['slug' => $post->slug]);
}

/** @return list<PublicContentChanged> */
function purges(): array
{
    return Event::dispatched(PublicContentChanged::class)->map(fn (array $call) => $call[0])->values()->all();
}

/** @return list<string> */
function purgedTags(): array
{
    return array_values(array_unique(array_merge(...array_map(fn (PublicContentChanged $event): array => $event->tags, purges()) ?: [[]])));
}

it('offers a guest post page to the cache under its purge tags', function (): void {
    $post = cachedPost();

    $result = cachedPageFor($post);

    expect($result?->cacheable)->toBeTrue()
        ->and($result?->cacheTags)->toBe(['blog:post:'.$post->id, 'blog:posts']);
});

it('expires the cached page when the next post goes live', function (): void {
    $post = cachedPost();
    $soon = now()->addHours(2)->startOfMinute();
    cachedPost(['published_at' => $soon]);

    expect(cachedPageFor($post)?->expiresAt?->getTimestamp())->toBe($soon->getTimestamp());
});

it('expires the cached page when its comment form closes', function (): void {
    $settings = BlogSettings::get();
    $settings->enable_comments = true;
    $settings->comments_close_after_days = 10;
    $settings->save();

    $published = now()->subDays(3)->startOfMinute();
    $post = cachedPost(['published_at' => $published, 'allow_comments' => true]);

    expect(cachedPageFor($post)?->expiresAt?->getTimestamp())->toBe($published->copy()->addDays(10)->getTimestamp());
});

it('never offers a signed-in visitor\'s page to the cache', function (): void {
    $post = cachedPost();
    $this->actingAs(User::factory()->create());

    expect(cachedPageFor($post)?->cacheable)->toBeFalse();
});

it('never offers a page carrying a flash message to the cache', function (): void {
    $post = cachedPost();
    $session = app('session.store');
    $session->flash('blog.comment_notice', 'pending');
    request()->setLaravelSession($session);

    expect(cachedPageFor($post)?->cacheable)->toBeFalse();
});

it('purges a post and the listings when a post is published', function (): void {
    $post = cachedPost(['status' => PostStatus::Draft, 'published_at' => null]);
    Event::fake([PublicContentChanged::class]);

    $post->update(['status' => PostStatus::Published, 'published_at' => now()->subMinute()]);

    expect(purgedTags())->toContain('blog:post:'.$post->id)
        ->toContain('blog:posts');
});

it('purges a live post on an autosave too', function (): void {
    $post = cachedPost();
    Event::fake([PublicContentChanged::class]);

    $post->autosaving = true;
    $post->update(['title' => 'Edited in the background']);

    expect(purgedTags())->toContain('blog:post:'.$post->id);
});

it('purges when a live post is unpublished or trashed', function (): void {
    $unpublished = cachedPost();
    $trashed = cachedPost();
    Event::fake([PublicContentChanged::class]);

    $unpublished->update(['status' => PostStatus::Draft]);
    $trashed->delete();

    expect(purgedTags())->toContain('blog:post:'.$unpublished->id)
        ->toContain('blog:post:'.$trashed->id);
});

it('leaves the cache alone for a draft nobody can see', function (): void {
    $draft = cachedPost(['status' => PostStatus::Draft, 'published_at' => null]);
    Event::fake([PublicContentChanged::class]);

    $draft->update(['title' => 'Still a draft']);

    expect(purges())->toBe([]);
});

it('purges a post page when one of its comments is approved, edited or removed', function (): void {
    $post = cachedPost();
    $comment = Comment::create(['post_id' => $post->id, 'author_name' => 'A', 'author_email' => 'a@b.test', 'body' => 'Hi']);
    Event::fake([PublicContentChanged::class]);

    $comment->update(['status' => CommentStatus::Approved]);
    expect(purgedTags())->toBe(['blog:post:'.$post->id]);

    Event::fake([PublicContentChanged::class]);
    $comment->update(['status' => CommentStatus::Spam]);
    expect(purgedTags())->toBe(['blog:post:'.$post->id]);
});

it('does not purge for a comment nobody can see yet', function (): void {
    $post = cachedPost();
    Event::fake([PublicContentChanged::class]);

    $pending = Comment::create(['post_id' => $post->id, 'author_name' => 'A', 'author_email' => 'a@b.test', 'body' => 'Hi']);
    $pending->delete();

    expect(purges())->toBe([]);
});

it('purges when a removed comment had been approved', function (): void {
    $post = cachedPost();
    $comment = Comment::create(['post_id' => $post->id, 'body' => 'Hi', 'status' => CommentStatus::Approved]);
    Event::fake([PublicContentChanged::class]);

    $comment->delete();

    expect(purgedTags())->toBe(['blog:post:'.$post->id]);
});

it('purges every post page when a category or tag changes', function (): void {
    $category = Category::create(['name' => 'News', 'slug' => 'news']);
    $tag = Tag::create(['name' => 'Laravel', 'slug' => 'laravel']);
    Event::fake([PublicContentChanged::class]);

    $category->update(['name' => 'Updates']);
    expect(purgedTags())->toContain('blog:posts');

    Event::fake([PublicContentChanged::class]);
    $tag->update(['name' => 'PHP']);
    expect(purgedTags())->toContain('blog:posts');

    Event::fake([PublicContentChanged::class]);
    $tag->delete();
    expect(purgedTags())->toContain('blog:posts');
});

it('purges everything when the blog settings are saved', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    $role = Role::create(['handle' => 'blog-settings-'.uniqid(), 'name' => 'Settings']);
    $role->grant('blog.settings.manage');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);

    Event::fake([PublicContentChanged::class]);

    Livewire::test(BlogSettingsPage::class)->call('save')->assertHasNoErrors();

    expect(collect(purges())->contains(fn (PublicContentChanged $event): bool => $event->everything))->toBeTrue();
});
