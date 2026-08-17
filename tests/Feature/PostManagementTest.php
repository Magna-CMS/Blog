<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Magna\Auth\PermissionRegistry;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use Magna\Webhooks\Jobs\DispatchWebhookJob;
use Magna\Webhooks\WebhookDelivery;
use Magna\Webhooks\WebhookSubscription;
use MagnaCms\Blog\Enums\CommentStatus;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Http\Controllers\CommentController;
use MagnaCms\Blog\Http\Resources\PostTransformer;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Comment;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Models\Tag;
use MagnaCms\Blog\Privacy\PersonalDataService;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\CommentPolicy;
use MagnaCms\Blog\Support\SlugGenerator;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

it('enables the plugin and registers its permissions', function (): void {
    $this->assertPluginEnabled('magna-cms/blog');

    $registry = app(PermissionRegistry::class);
    expect($registry->has('blog.posts.view'))->toBeTrue()
        ->and($registry->has('blog.posts.publish'))->toBeTrue()
        ->and($registry->has('blog.settings.manage'))->toBeTrue();
});

it('generates unique slugs across a table', function (): void {
    $generator = app(SlugGenerator::class);

    $first = Category::create(['name' => 'Tech', 'slug' => $generator->generate('Tech', 'blog_categories')]);
    $second = Category::create(['name' => 'Tech', 'slug' => $generator->generate('Tech', 'blog_categories')]);

    expect($first->slug)->toBe('tech')
        ->and($second->slug)->toBe('tech-2');
});

it('casts status/visibility enums and content json', function (): void {
    $post = Post::create([
        'title' => 'Hello',
        'slug' => 'hello',
        'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'hi']]]],
        'status' => 'scheduled',
        'visibility' => 'public',
    ]);

    $post->refresh();

    expect($post->status)->toBeInstanceOf(PostStatus::class)
        ->and($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->visibility)->toBe(PostVisibility::Public)
        ->and($post->content['blocks'])->toHaveCount(1);
});

it('hashes the post password and hides it from serialization', function (): void {
    $post = Post::create([
        'title' => 'Secret',
        'slug' => 'secret',
        'visibility' => 'password',
        'password' => 'topsecret',
    ]);

    expect(Hash::check('topsecret', $post->getAttributes()['password']))->toBeTrue()
        ->and($post->toArray())->not->toHaveKey('password');
});

it('lists a full descendant subtree for cycle-safe parent exclusion', function (): void {
    $root = Category::create(['name' => 'Root', 'slug' => 'root']);
    $child = Category::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $root->id]);
    $grand = Category::create(['name' => 'Grand', 'slug' => 'grand', 'parent_id' => $child->id]);
    $other = Category::create(['name' => 'Other', 'slug' => 'other']);

    $ids = $root->descendantIds();

    expect($ids)->toContain($child->id)
        ->and($ids)->toContain($grand->id)
        ->and($ids)->not->toContain($other->id)
        ->and($ids)->not->toContain($root->id);
});

it('resolves descendant ids without hanging on a corrupt cyclic tree', function (): void {
    $a = Category::create(['name' => 'A', 'slug' => 'a']);
    $b = Category::create(['name' => 'B', 'slug' => 'b', 'parent_id' => $a->id]);
    // Force a cycle directly in the DB (the UI prevents this; a corrupt row must
    // still not loop forever).
    $a->forceFill(['parent_id' => $b->id])->saveQuietly();

    expect($a->descendantIds())->toContain($b->id);
});

it('supports nested categories and tag attachment', function (): void {
    $parent = Category::create(['name' => 'Tech', 'slug' => 'tech']);
    $child = Category::create(['name' => 'Laravel', 'slug' => 'laravel', 'parent_id' => $parent->id]);

    expect($child->parent->name)->toBe('Tech')
        ->and($parent->children)->toHaveCount(1);

    $post = Post::create(['title' => 'P', 'slug' => 'p', 'category_id' => $parent->id]);
    $tag = Tag::create(['name' => 'PHP', 'slug' => 'php']);
    $post->tags()->attach($tag->id);

    expect($post->fresh()->tags)->toHaveCount(1)
        ->and($post->category->name)->toBe('Tech');
});

it('trashes a post via soft delete and can restore it', function (): void {
    $post = Post::create(['title' => 'Trash me', 'slug' => 'trash-me']);

    $post->delete();
    expect(Post::query()->find($post->id))->toBeNull()
        ->and(Post::withTrashed()->find($post->id)->trashed())->toBeTrue();

    $post->restore();
    expect(Post::query()->find($post->id))->not->toBeNull();
});

it('writes a revision automatically on create and update', function (): void {
    $post = Post::create(['title' => 'Versioned', 'slug' => 'versioned']);
    expect($post->revisions()->count())->toBe(1);

    $post->update(['title' => 'Versioned v2']);
    expect($post->revisions()->count())->toBe(2)
        ->and($post->revisions()->first()->payload['title'])->toBe('Versioned v2');
});

it('prunes revisions beyond the configured maximum', function (): void {
    $settings = BlogSettings::get();
    $settings->max_revisions = 3;
    $settings->save();

    $post = Post::create(['title' => 'Prune', 'slug' => 'prune']); // 1 revision
    for ($i = 0; $i < 5; $i++) {
        $post->update(['title' => 'Prune '.$i]);
    }

    expect($post->revisions()->count())->toBe(3);
});

it('publishes scheduled posts that are due via the command', function (): void {
    $due = Post::create([
        'title' => 'Due', 'slug' => 'due', 'status' => 'scheduled', 'published_at' => now()->subMinute(),
    ]);
    $future = Post::create([
        'title' => 'Future', 'slug' => 'future', 'status' => 'scheduled', 'published_at' => now()->addDay(),
    ]);

    $this->artisan('blog:publish-scheduled')->assertSuccessful();

    expect($due->fresh()->status)->toBe(PostStatus::Published)
        ->and($future->fresh()->status)->toBe(PostStatus::Scheduled);
});

it('fires a webhook delivery when a post is published', function (): void {
    Queue::fake();

    WebhookSubscription::create([
        'url' => 'https://example.test/hook',
        'secret' => 'shh',
        'events' => ['blog.post.published'],
        'active' => true,
    ]);

    Post::create(['title' => 'Hooked', 'slug' => 'hooked', 'status' => 'published']);

    expect(WebhookDelivery::query()->where('event', 'blog.post.published')->count())->toBe(1);
    Queue::assertPushed(DispatchWebhookJob::class);
});

it('does not fire webhooks when there are no matching subscriptions', function (): void {
    Queue::fake();

    Post::create(['title' => 'Quiet', 'slug' => 'quiet', 'status' => 'published']);

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('opens or closes comments based on settings and the per-post flag', function (): void {
    $settings = BlogSettings::get();
    $settings->enable_comments = true;
    $settings->require_login_to_comment = true;
    $settings->comments_close_after_days = 0;
    $settings->save();

    $policy = app(CommentPolicy::class);
    $open = Post::create(['title' => 'Open', 'slug' => 'open', 'allow_comments' => true]);
    $off = Post::create(['title' => 'Off', 'slug' => 'off', 'allow_comments' => false]);

    expect($policy->open($open))->toBeTrue()
        ->and($policy->open($off))->toBeFalse()
        ->and($policy->requiresLogin())->toBeTrue();

    $settings->enable_comments = false;
    $settings->save();
    expect($policy->open($open))->toBeFalse();
});

it('auto-closes comments after the configured number of days', function (): void {
    $settings = BlogSettings::get();
    $settings->enable_comments = true;
    $settings->comments_close_after_days = 7;
    $settings->save();

    $policy = app(CommentPolicy::class);
    $recent = Post::create(['title' => 'Recent', 'slug' => 'recent', 'allow_comments' => true, 'published_at' => now()->subDays(2)]);
    $old = Post::create(['title' => 'Old', 'slug' => 'old', 'allow_comments' => true, 'published_at' => now()->subDays(30)]);

    expect($policy->open($recent))->toBeTrue()
        ->and($policy->open($old))->toBeFalse();

    $payload = app(PostTransformer::class)->full($recent);
    expect($payload['comments']['enabled'])->toBeTrue()
        ->and($payload)->not->toHaveKey('password');
});

it('resolves dynamic blocks (TOC, excerpt, related posts) in the delivery payload', function (): void {
    $category = Category::create(['name' => 'Tech', 'slug' => 'tech']);
    Post::create([
        'title' => 'Sibling', 'slug' => 'sibling', 'category_id' => $category->id,
        'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay(),
    ]);
    $post = Post::create([
        'title' => 'Main', 'slug' => 'main', 'category_id' => $category->id,
        'status' => 'published', 'visibility' => 'public', 'published_at' => now(), 'excerpt' => 'My summary',
        'content' => ['blocks' => [
            ['type' => 'header', 'data' => ['text' => 'Intro', 'level' => 2]],
            ['type' => 'header', 'data' => ['text' => 'Deep', 'level' => 4]],
            ['type' => 'toc', 'data' => ['depth' => 3]],
            ['type' => 'postExcerpt', 'data' => []],
            ['type' => 'relatedPosts', 'data' => ['count' => 3, 'by' => 'category']],
        ]],
    ]);

    $blocks = collect(app(PostTransformer::class)->full($post)['content']['blocks']);

    $toc = $blocks->firstWhere('type', 'toc');
    expect($toc['data']['items'])->toHaveCount(1) // depth 3 excludes the H4
        ->and($toc['data']['items'][0]['anchor'])->toBe('intro');

    expect($blocks->firstWhere('type', 'postExcerpt')['data']['text'])->toBe('My summary');

    $related = $blocks->firstWhere('type', 'relatedPosts');
    expect($related['data']['posts'])->toHaveCount(1)
        ->and($related['data']['posts'][0]['slug'])->toBe('sibling');
});

it('accepts a comment held for moderation and sanitises the body', function (): void {
    $post = Post::create(['title' => 'P', 'slug' => 'commentable', 'status' => 'published', 'visibility' => 'public', 'published_at' => now(), 'allow_comments' => true]);
    $settings = BlogSettings::get();
    $settings->enable_comments = true;
    $settings->comment_moderation = 'pending';
    $settings->save();

    $request = Request::create('/', 'POST', [
        'body' => 'Nice <script>x</script> post', 'author_name' => 'Jane', 'author_email' => 'jane@test.dev',
    ]);
    app(CommentController::class)->store($request, 'commentable');

    $comment = Comment::query()->first();
    expect($comment->status)->toBe(CommentStatus::Pending)
        ->and($comment->body)->not->toContain('<script>')
        ->and($comment->author_id)->toBeNull();
});

it('auto-approves comments when moderation is set to auto', function (): void {
    $post = Post::create(['title' => 'Q', 'slug' => 'auto-comment', 'status' => 'published', 'visibility' => 'public', 'published_at' => now(), 'allow_comments' => true]);
    $settings = BlogSettings::get();
    $settings->enable_comments = true;
    $settings->comment_moderation = 'auto';
    $settings->save();

    $request = Request::create('/', 'POST', ['body' => 'hi', 'author_name' => 'A', 'author_email' => 'a@test.dev']);
    app(CommentController::class)->store($request, 'auto-comment');

    expect(Comment::query()->first()->status)->toBe(CommentStatus::Approved);
});

it('serves only approved comments from the delivery API', function (): void {
    $post = Post::create(['title' => 'R', 'slug' => 'served', 'status' => 'published', 'visibility' => 'public', 'published_at' => now(), 'allow_comments' => true]);
    Comment::create(['post_id' => $post->id, 'author_name' => 'A', 'body' => 'ok', 'status' => 'approved']);
    Comment::create(['post_id' => $post->id, 'author_name' => 'B', 'body' => 'wait', 'status' => 'pending']);

    $json = app(CommentController::class)->index('served')->getData(true);

    expect($json['data'])->toHaveCount(1)
        ->and($json['data'][0]['body'])->toBe('ok');
});

it('erases personal data by severing the author link and anonymising comments', function (): void {
    $user = User::factory()->create();
    $post = Post::create(['title' => 'By user', 'slug' => 'by-user', 'author_id' => $user->id]);
    $comment = Comment::create([
        'post_id' => $post->id, 'author_id' => $user->id,
        'author_name' => 'Jane', 'author_email' => 'jane@test.dev', 'body' => 'hi',
    ]);

    app(PersonalDataService::class)->erase($user->id);

    expect($post->fresh()->author_id)->toBeNull()
        ->and($comment->fresh()->author_id)->toBeNull()
        ->and($comment->fresh()->author_name)->toBeNull();
});
