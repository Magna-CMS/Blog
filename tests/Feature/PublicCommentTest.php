<?php

declare(strict_types=1);

/**
 * The public comment form's endpoint (blog 1.2.0, deferred v1 limitation E).
 *
 * POST /_blog/comments/{post} lives in the web group (session + CSRF, the
 * token supplied by core's form-token endpoint because the post page may
 * be cached) under /_blog so it can never collide with a permalink. It
 * answers with a redirect back to the post's comments and a notice, never
 * with a bot-readable verdict.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Magna\Frontend\Forms\SignedFormTimestamp;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Enums\CommentStatus;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Models\Comment;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
    // Routes registered by a plugin enabled after boot are not in the name index yet.
    app('router')->getRoutes()->refreshNameLookups();

    $settings = BlogSettings::get();
    $settings->enable_comments = true;
    $settings->require_login_to_comment = false;
    $settings->comment_moderation = 'pending';
    $settings->save();
});

/** @param  array<string, mixed>  $attributes */
function talkPost(array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'Talk about it',
        'slug' => 'talk',
        'locale' => 'en',
        'status' => PostStatus::Published,
        'visibility' => PostVisibility::Public,
        'published_at' => now()->subDay(),
        'allow_comments' => true,
    ], $attributes));
}

/** A render stamp issued $secondsAgo, as the cached form would carry it. */
function stampFrom(int $secondsAgo, string $purpose = 'blog-comments'): string
{
    $now = now();
    Carbon::setTestNow($now->copy()->subSeconds($secondsAgo));
    $stamp = SignedFormTimestamp::issue($purpose);
    Carbon::setTestNow($now);

    return $stamp;
}

/** @param  array<string, mixed>  $overrides */
function guestComment(array $overrides = []): array
{
    return array_merge([
        'body' => 'A thoughtful reply.',
        'author_name' => 'Jane',
        'author_email' => 'jane@example.test',
        '_ts' => stampFrom(30),
        'website' => '',
    ], $overrides);
}

it('lives in the web group, behind CSRF, rate limited and out of the permalink space', function (): void {
    $route = Route::getRoutes()->getByName('blog.public-comments.store');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('_blog/comments/{post}')
        ->and($route->methods())->toContain('POST')
        ->and($route->gatherMiddleware())->toContain('web')
        ->toContain('throttle:10,1');
});

it('holds a guest comment for moderation and says so', function (): void {
    $post = talkPost();

    $this->post('/_blog/comments/'.$post->id, guestComment())
        ->assertRedirect('/blog/talk#comments')
        ->assertSessionHas('blog.comment_notice', 'pending');

    $comment = Comment::query()->sole();
    expect($comment->status)->toBe(CommentStatus::Pending)
        ->and($comment->author_name)->toBe('Jane')
        ->and($comment->author_id)->toBeNull();
});

it('says approved when comments are auto-approved', function (): void {
    $settings = BlogSettings::get();
    $settings->comment_moderation = 'auto';
    $settings->save();

    $this->post('/_blog/comments/'.talkPost()->id, guestComment())
        ->assertSessionHas('blog.comment_notice', 'approved');

    expect(Comment::query()->sole()->status)->toBe(CommentStatus::Approved);
});

it('stores comment text as text, never markup', function (): void {
    $this->post('/_blog/comments/'.talkPost()->id, guestComment([
        'body' => '<script>alert(1)</script>Nice <b>post</b>',
        'author_name' => '<i>Jane</i>',
    ]));

    $comment = Comment::query()->sole();
    expect($comment->body)->toBe('alert(1)Nice post')
        ->and($comment->author_name)->toBe('Jane');
});

it('discards a bot quietly, with the same answer a person gets', function (Closure $overrides): void {
    $this->post('/_blog/comments/'.talkPost()->id, guestComment($overrides()))
        ->assertRedirect('/blog/talk#comments')
        ->assertSessionHas('blog.comment_notice', 'pending')
        ->assertSessionHasNoErrors();

    expect(Comment::query()->count())->toBe(0);
})->with([
    'honeypot filled' => [fn (): array => ['website' => 'http://spam.example']],
    'no render stamp' => [fn (): array => ['_ts' => null]],
    'forged render stamp' => [fn (): array => ['_ts' => now()->subMinute()->getTimestamp().'.deadbeef']],
    'stamp for another form' => [fn (): array => ['_ts' => stampFrom(30, 'forms')]],
    'submitted too fast' => [fn (): array => ['_ts' => SignedFormTimestamp::issue('blog-comments')]],
]);

it('sends a person back to the form with what went wrong', function (): void {
    $this->post('/_blog/comments/'.talkPost()->id, guestComment(['body' => str_repeat('x', 5001), 'author_email' => 'nope']))
        ->assertRedirect('/blog/talk#comments')
        ->assertSessionHasErrors(['body', 'author_email'], null, 'blogComment');

    expect(Comment::query()->count())->toBe(0);
});

it('refuses a comment that is only markup', function (): void {
    $this->post('/_blog/comments/'.talkPost()->id, guestComment(['body' => '<p></p>']))
        ->assertSessionHasErrors(['body'], null, 'blogComment');
});

it('refuses comments on a post that is closed to them', function (): void {
    $this->post('/_blog/comments/'.talkPost(['allow_comments' => false])->id, guestComment())
        ->assertSessionHasErrors(['body'], null, 'blogComment');

    expect(Comment::query()->count())->toBe(0);
});

it('does not admit a post a visitor cannot see', function (): void {
    $draft = talkPost(['status' => PostStatus::Draft, 'published_at' => null]);

    $this->post('/_blog/comments/'.$draft->id, guestComment())->assertNotFound();
    $this->post('/_blog/comments/not-a-number', guestComment())->assertNotFound();
});

it('asks a guest to sign in when the site requires it', function (): void {
    $settings = BlogSettings::get();
    $settings->require_login_to_comment = true;
    $settings->save();

    $this->post('/_blog/comments/'.talkPost()->id, guestComment())
        ->assertSessionHasErrors(['body'], null, 'blogComment');

    expect(Comment::query()->count())->toBe(0);
});

it('takes a signed-in commenter\'s identity from their account, not the form', function (): void {
    $settings = BlogSettings::get();
    $settings->require_login_to_comment = true;
    $settings->save();

    $user = User::factory()->create(['name' => 'Panel User', 'email' => 'panel@example.test']);

    $this->actingAs($user)->post('/_blog/comments/'.talkPost()->id, [
        'body' => 'Signed in.',
        'author_name' => 'Someone Else',
        'author_email' => 'forged@example.test',
        '_ts' => stampFrom(30),
    ])->assertSessionHasNoErrors();

    $comment = Comment::query()->sole();
    expect($comment->author_id)->toBe($user->id)
        ->and($comment->author_name)->toBe('Panel User')
        ->and($comment->author_email)->toBe('panel@example.test');
});

it('files a reply to a reply under the top-level comment', function (): void {
    $post = talkPost();
    $top = Comment::create(['post_id' => $post->id, 'body' => 'Top', 'status' => CommentStatus::Approved]);
    $reply = Comment::create(['post_id' => $post->id, 'parent_id' => $top->id, 'body' => 'Reply', 'status' => CommentStatus::Approved]);

    $this->post('/_blog/comments/'.$post->id, guestComment(['parent_id' => $reply->id]));

    expect(Comment::query()->latest('id')->first()?->parent_id)->toBe($top->id);
});

it('ignores a parent that belongs to another post or is not approved', function (): void {
    $post = talkPost();
    $elsewhere = Comment::create(['post_id' => talkPost(['slug' => 'other'])->id, 'body' => 'Elsewhere', 'status' => CommentStatus::Approved]);
    $pending = Comment::create(['post_id' => $post->id, 'body' => 'Pending']);

    $this->post('/_blog/comments/'.$post->id, guestComment(['parent_id' => $elsewhere->id]));
    $this->post('/_blog/comments/'.$post->id, guestComment(['parent_id' => $pending->id]));

    expect(Comment::query()->where('author_name', 'Jane')->pluck('parent_id')->all())->toBe([null, null]);
});

it('rate limits a flood of submissions', function (): void {
    $post = talkPost();

    foreach (range(1, 10) as $attempt) {
        $this->post('/_blog/comments/'.$post->id, guestComment())->assertRedirect();
    }

    $this->post('/_blog/comments/'.$post->id, guestComment())->assertTooManyRequests();
});

it('sends a guest to the panel sign-in and back to the comments after', function (): void {
    if (! Route::has('filament.magna.auth.login')) {
        test()->markTestSkipped('The panel login route is not registered in this run.');
    }
    $post = talkPost();

    $this->get('/_blog/comments/'.$post->id.'/sign-in')
        ->assertRedirect(URL::route('filament.magna.auth.login'))
        ->assertSessionHas('url.intended', url('/blog/talk').'#comments');
});
