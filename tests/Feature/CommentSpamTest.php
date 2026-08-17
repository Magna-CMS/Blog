<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Http\Controllers\CommentController;
use MagnaCms\Blog\Models\Comment;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\Spam\AkismetSpamCheck;
use MagnaCms\Blog\Support\Spam\HoneypotSpamCheck;
use MagnaCms\Blog\Support\Spam\SpamSubmission;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');

    $settings = BlogSettings::get();
    $settings->enable_comments = true;
    $settings->comment_moderation = 'auto';
    $settings->save();
});

function commentablePost(string $slug = 'commentable'): Post
{
    return Post::create([
        'title' => 'P', 'slug' => $slug, 'status' => 'published',
        'visibility' => 'public', 'published_at' => now(), 'allow_comments' => true,
    ]);
}

function submit(string $slug, array $payload): void
{
    app(CommentController::class)->store(Request::create('/', 'POST', $payload), $slug);
}

it('rejects a submission whose honeypot field is filled', function (): void {
    commentablePost();

    expect(fn () => submit('commentable', [
        'body' => 'buy pills', 'author_name' => 'Bot', 'author_email' => 'bot@spam.test',
        'website' => 'http://spam.example',
    ]))->toThrow(ValidationException::class);

    expect(Comment::query()->count())->toBe(0);
});

it('rejects a submission sent faster than the minimum time', function (): void {
    commentablePost();

    expect(fn () => submit('commentable', [
        'body' => 'too fast', 'author_name' => 'Bot', 'author_email' => 'bot@spam.test',
        'form_started_at' => now()->getTimestamp(), // 0s elapsed < 2s
    ]))->toThrow(ValidationException::class);

    expect(Comment::query()->count())->toBe(0);
});

it('accepts a clean submission with an empty honeypot and a human delay', function (): void {
    commentablePost();

    submit('commentable', [
        'body' => 'Great post!', 'author_name' => 'Jane', 'author_email' => 'jane@test.dev',
        'website' => '', 'form_started_at' => now()->subSeconds(30)->getTimestamp(),
    ]);

    expect(Comment::query()->count())->toBe(1);
});

it('honeypot check flags a filled trap and a too-fast submit', function (): void {
    $check = new HoneypotSpamCheck(2);
    $base = ['body' => 'x', 'authorName' => 'a', 'authorEmail' => 'a@b.test'];

    expect($check->isSpam(new SpamSubmission(...$base, honeypot: 'x')))->toBeTrue()
        ->and($check->isSpam(new SpamSubmission(...$base, elapsedSeconds: 1)))->toBeTrue()
        ->and($check->isSpam(new SpamSubmission(...$base, elapsedSeconds: 5)))->toBeFalse()
        ->and($check->isSpam(new SpamSubmission(...$base)))->toBeFalse();
});

it('akismet flags spam and fails open without a key', function (): void {
    $submission = new SpamSubmission(body: 'x', authorName: 'a', authorEmail: 'a@b.test', ip: '1.2.3.4');

    Http::fake(['*.rest.akismet.com/*' => Http::response('true')]);
    $configured = new AkismetSpamCheck(new HoneypotSpamCheck(2), 'test-key', 'https://site.test');
    expect($configured->isSpam($submission))->toBeTrue();

    // No key → honeypot-only, never calls the API, treats clean content as clean.
    Http::fake();
    $unconfigured = new AkismetSpamCheck(new HoneypotSpamCheck(2), null, null);
    expect($unconfigured->isSpam($submission))->toBeFalse();
    Http::assertNothingSent();
});
