<?php

declare(strict_types=1);

/**
 * The comments section's markup — a FROZEN contract.
 *
 * Lekha 1.12.0 (and any other theme) styles this section with CSS alone,
 * because a theme cannot override a plugin's view. Every class, id and
 * nesting rule asserted here is part of that contract; changing one breaks
 * every theme that styled it. In particular the section is a SIBLING after
 * </article>, never inside it: themes carry a generic `article > nav` rule
 * that must not reach it.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Magna\Frontend\RenderContext;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Enums\CommentStatus;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Frontend\PostPageProvider;
use MagnaCms\Blog\Models\Comment;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');

    $settings = BlogSettings::get();
    $settings->enable_comments = true;
    $settings->require_login_to_comment = false;
    $settings->comments_close_after_days = 0;
    $settings->save();
});

/** @param  array<string, mixed>  $attributes */
function markupPost(array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'Commented',
        'slug' => 'commented',
        'locale' => 'en',
        'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]]],
        'status' => PostStatus::Published,
        'visibility' => PostVisibility::Public,
        'published_at' => now()->subDay(),
        'allow_comments' => true,
    ], $attributes));
}

/** The post page's own view, rendered from what the provider resolved. */
function renderPostPage(?string $flash = null): DOMXPath
{
    $request = Request::create('/blog/commented');
    $session = app('session.store');
    if ($flash !== null) {
        $session->flash('blog.comment_notice', $flash);
    }
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => auth()->user());
    app()->instance('request', $request);
    app(RenderContext::class)->setRequest($request);

    $result = app(PostPageProvider::class)->resolve(['slug' => 'commented']);
    expect($result)->not->toBeNull();

    $html = view('blog::frontend.post', $result->data)->render();

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8"?><div id="root">'.$html.'</div>');
    libxml_clear_errors();

    return new DOMXPath($document);
}

function hasClass(string $class): string
{
    return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
}

/** @return array{0: Comment, 1: Comment} */
function threadOnMarkupPost(Post $post): array
{
    $top = Comment::create([
        'post_id' => $post->id, 'author_name' => '<b>Ann</b>', 'author_email' => 'ann@example.test',
        'body' => "First line\nsecond <script>alert(1)</script>", 'status' => CommentStatus::Approved,
    ]);
    $reply = Comment::create([
        'post_id' => $post->id, 'parent_id' => $top->id, 'author_name' => 'Bob',
        'body' => 'A reply', 'status' => CommentStatus::Approved,
    ]);
    Comment::create(['post_id' => $post->id, 'author_name' => 'Hidden', 'body' => 'Pending one']);

    return [$top, $reply];
}

it('renders the section as a sibling after the article, never inside it', function (): void {
    markupPost();
    $xpath = renderPostPage();

    $section = $xpath->query("//section[@id='comments' and ".hasClass('post-comments').']');

    expect($section->length)->toBe(1)
        ->and($xpath->query('//article//section')->length)->toBe(0)
        ->and($xpath->query("//article/following-sibling::section[@id='comments']")->length)->toBe(1)
        ->and($xpath->query("//section[@id='comments']/h2[".hasClass('post-comments__title').']')->length)->toBe(1);
});

it('renders approved comments and their replies in the contract shape', function (): void {
    [$top, $reply] = threadOnMarkupPost(markupPost());
    $xpath = renderPostPage();

    $item = '//ol['.hasClass('post-comments__list').']/li['.hasClass('post-comment')." and @id='comment-{$top->id}']";

    expect($xpath->query($item)->length)->toBe(1)
        ->and($xpath->query($item.'/*['.hasClass('post-comment__meta').']/*['.hasClass('post-comment__author').']')->item(0)?->textContent)->toBe('<b>Ann</b>')
        ->and($xpath->query($item.'/*['.hasClass('post-comment__meta').']/time['.hasClass('post-comment__date').' and @datetime]')->length)->toBe(1)
        ->and($xpath->query($item.'/*['.hasClass('post-comment__text').']')->length)->toBe(1)
        ->and($xpath->query($item.'/details['.hasClass('post-comment__reply').']//form['.hasClass('post-comments__form').']')->length)->toBe(1)
        ->and($xpath->query($item.'/ol['.hasClass('post-comment__replies').']/li['.hasClass('post-comment').' and '.hasClass('post-comment--reply')." and @id='comment-{$reply->id}']")->length)->toBe(1)
        // Pending comments are nobody's business but the moderator's.
        ->and($xpath->query("//*[contains(., 'Pending one')]")->length)->toBe(0);
});

it('escapes everything a commenter wrote', function (): void {
    threadOnMarkupPost(markupPost());
    $xpath = renderPostPage();

    // What commenters wrote only: the form's token field brings a script of its own.
    $written = '//*['.hasClass('post-comment__text').' or '.hasClass('post-comment__meta').']';

    expect($xpath->query($written.'//script')->length)->toBe(0)
        ->and($xpath->query($written.'//b')->length)->toBe(0)
        ->and($xpath->query('//*['.hasClass('post-comment__author').']')->item(0)?->textContent)->toBe('<b>Ann</b>')
        ->and($xpath->query('//*['.hasClass('post-comment__text').']')->item(0)?->textContent)->toContain('<script>alert(1)</script>');
});

it('renders the comment form with everything a cached page needs', function (): void {
    markupPost();
    $xpath = renderPostPage();

    $form = "//section[@id='comments']/form[".hasClass('post-comments__form').']';

    expect($xpath->query($form)->length)->toBe(1)
        ->and($xpath->query($form."[@method='post' and @action]")->length)->toBe(1)
        ->and($xpath->query($form."//input[@name='_token' and @data-magna-form-token and @value='']")->length)->toBe(1)
        ->and($xpath->query($form."//input[@name='_ts' and @value!='']")->length)->toBe(1)
        ->and($xpath->query($form."//input[@name='website']")->length)->toBe(1)
        ->and($xpath->query($form."//input[@name='parent_id']")->length)->toBe(1)
        ->and($xpath->query($form."//input[@name='author_name']")->length)->toBe(1)
        ->and($xpath->query($form."//input[@name='author_email']")->length)->toBe(1)
        ->and($xpath->query($form."//textarea[@name='body']")->length)->toBe(1)
        ->and($xpath->query($form.'//button['.hasClass('post-comments__submit').']')->length)->toBe(1);
});

it('leaves name and email off the form for a signed-in commenter', function (): void {
    markupPost();
    $this->actingAs(User::factory()->create());
    $xpath = renderPostPage();

    expect($xpath->query("//input[@name='author_name']")->length)->toBe(0)
        ->and($xpath->query("//textarea[@name='body']")->length)->toBe(1);
});

it('announces a held comment and an approved one', function (string $notice): void {
    markupPost();
    $xpath = renderPostPage($notice);

    expect($xpath->query("//section[@id='comments']/p[".hasClass('post-comments__notice').' and '.hasClass('post-comments__notice--'.$notice)." and @role='status']")->length)->toBe(1);
})->with(['pending', 'approved']);

it('says when comments are closed, keeping what was said', function (): void {
    $settings = BlogSettings::get();
    $settings->comments_close_after_days = 1;
    $settings->save();

    $post = markupPost(['published_at' => Carbon::now()->subDays(3)]);
    threadOnMarkupPost($post);
    $xpath = renderPostPage();

    expect($xpath->query("//section[@id='comments']/p[".hasClass('post-comments__closed').']')->length)->toBe(1)
        ->and($xpath->query('//form['.hasClass('post-comments__form').']')->length)->toBe(0)
        ->and($xpath->query('//details['.hasClass('post-comment__reply').']')->length)->toBe(0)
        ->and($xpath->query('//li['.hasClass('post-comment').']')->length)->toBe(2);
});

it('asks a guest to sign in when the site requires it', function (): void {
    $settings = BlogSettings::get();
    $settings->require_login_to_comment = true;
    $settings->save();

    markupPost();
    $xpath = renderPostPage();

    expect($xpath->query("//section[@id='comments']/p[".hasClass('post-comments__login').']//a[@href]')->length)->toBe(1)
        ->and($xpath->query('//form['.hasClass('post-comments__form').']')->length)->toBe(0);
});

it('renders no section at all when comments are off and nothing was said', function (): void {
    markupPost(['allow_comments' => false]);

    expect(renderPostPage()->query("//section[@id='comments']")->length)->toBe(0);
});
