<?php

declare(strict_types=1);

/**
 * One comment submission path for both front doors.
 *
 * The delivery API (POST /api/v1/blog/posts/{slug}/comments) and the public
 * form (POST /_blog/comments/{post}) both hand the request to
 * CommentSubmissionHandler, so the policy check, the spam defences, the
 * reply rule, the stripping and the moderation decision cannot drift apart.
 * What legitimately differs is pinned here too: the API keeps its JSON
 * contract and its loud spam rejection, and never takes identity from a
 * session it does not have.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Magna\Frontend\Forms\SignedFormTimestamp;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Enums\CommentStatus;
use MagnaCms\Blog\Http\Controllers\CommentController;
use MagnaCms\Blog\Models\Comment;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\CommentChannel;
use MagnaCms\Blog\Support\CommentSubmissionHandler;
use MagnaCms\Blog\Support\Spam\CommentRejectedAsSpam;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');

    $settings = BlogSettings::get();
    $settings->enable_comments = true;
    $settings->comment_moderation = 'pending';
    $settings->save();
});

function handlerPost(): Post
{
    return Post::create([
        'title' => 'P', 'slug' => 'handled', 'status' => 'published',
        'visibility' => 'public', 'published_at' => now()->subDay(), 'allow_comments' => true,
    ]);
}

/** @param  array<string, mixed>  $payload */
function handlerRequest(array $payload, ?User $user = null): Request
{
    $request = Request::create('/', 'POST', $payload);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function oldStamp(): string
{
    $now = now();
    Carbon::setTestNow($now->copy()->subMinute());
    $stamp = SignedFormTimestamp::issue(CommentSubmissionHandler::PURPOSE);
    Carbon::setTestNow($now);

    return $stamp;
}

/** @return array<string, mixed> */
function storedShape(Comment $comment): array
{
    return $comment->only(['post_id', 'parent_id', 'author_id', 'author_name', 'author_email', 'body']) + ['status' => $comment->status->value];
}

it('stores the same comment from either front door', function (): void {
    $post = handlerPost();
    $payload = ['body' => ' <b>Same</b> words ', 'author_name' => 'Jane', 'author_email' => 'jane@example.test', '_ts' => oldStamp()];
    $handler = app(CommentSubmissionHandler::class);

    $viaApi = $handler->submit($post, handlerRequest($payload), CommentChannel::Api);
    $viaWeb = $handler->submit($post, handlerRequest($payload), CommentChannel::Web);

    expect(storedShape($viaApi))->toBe(storedShape($viaWeb))
        ->and($viaApi->body)->toBe('Same words');
});

it('serves the API through the handler, keeping its JSON contract', function (): void {
    handlerPost();

    $response = app(CommentController::class)->store(handlerRequest([
        'body' => 'Hello', 'author_name' => 'Jane', 'author_email' => 'jane@example.test',
    ]), 'handled');

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getData(true)['data'])->toMatchArray(['status' => 'pending', 'awaiting_moderation' => true]);
});

it('rejects API spam loudly, as before, and the web quietly', function (): void {
    $post = handlerPost();
    $handler = app(CommentSubmissionHandler::class);
    $spam = ['body' => 'pills', 'author_name' => 'Bot', 'author_email' => 'bot@example.test', 'website' => 'http://spam.example', '_ts' => oldStamp()];

    expect(fn () => $handler->submit($post, handlerRequest($spam), CommentChannel::Api))->toThrow(ValidationException::class)
        // Quiet means the web controller can tell spam from a mistake and answer as if accepted.
        ->and(fn () => $handler->submit($post, handlerRequest($spam), CommentChannel::Web))->toThrow(CommentRejectedAsSpam::class);

    expect(Comment::query()->count())->toBe(0);
});

it('checks a signed render stamp on the API when one is sent', function (): void {
    $post = handlerPost();

    expect(fn () => app(CommentSubmissionHandler::class)->submit($post, handlerRequest([
        'body' => 'x', 'author_name' => 'Bot', 'author_email' => 'bot@example.test', '_ts' => '123.forged',
    ]), CommentChannel::Api))->toThrow(ValidationException::class);
});

it('requires the signed render stamp on the web form', function (): void {
    expect(fn () => app(CommentSubmissionHandler::class)->submit(handlerPost(), handlerRequest([
        'body' => 'x', 'author_name' => 'Jane', 'author_email' => 'jane@example.test',
    ]), CommentChannel::Web))->toThrow(CommentRejectedAsSpam::class);
});

it('never takes identity from a request user on the API', function (): void {
    $user = User::factory()->create();

    $comment = app(CommentSubmissionHandler::class)->submit(handlerPost(), handlerRequest([
        'body' => 'Hi', 'author_name' => 'Jane', 'author_email' => 'jane@example.test',
    ], $user), CommentChannel::Api);

    expect($comment->author_id)->toBeNull()
        ->and($comment->author_name)->toBe('Jane');
});

it('enforces require-login only on the web form', function (): void {
    $settings = BlogSettings::get();
    $settings->require_login_to_comment = true;
    $settings->save();

    $post = handlerPost();
    $payload = ['body' => 'Hi', 'author_name' => 'Jane', 'author_email' => 'jane@example.test', '_ts' => oldStamp()];
    $handler = app(CommentSubmissionHandler::class);

    // The API surfaces the flag for its frontend to enforce, as in 1.x.
    expect($handler->submit($post, handlerRequest($payload), CommentChannel::Api))->toBeInstanceOf(Comment::class)
        ->and(fn () => $handler->submit($post, handlerRequest($payload), CommentChannel::Web))->toThrow(ValidationException::class);
});

it('files an API reply to a reply under the top-level comment', function (): void {
    $post = handlerPost();
    $top = Comment::create(['post_id' => $post->id, 'body' => 'Top', 'status' => CommentStatus::Approved]);
    $reply = Comment::create(['post_id' => $post->id, 'parent_id' => $top->id, 'body' => 'Reply', 'status' => CommentStatus::Approved]);

    $comment = app(CommentSubmissionHandler::class)->submit($post, handlerRequest([
        'body' => 'Nested', 'author_name' => 'Jane', 'author_email' => 'jane@example.test', 'parent_id' => $reply->id,
    ]), CommentChannel::Api);

    expect($comment->parent_id)->toBe($top->id);
});
