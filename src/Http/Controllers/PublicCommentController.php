<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use MagnaCms\Blog\Enums\CommentStatus;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\CommentChannel;
use MagnaCms\Blog\Support\CommentSubmissionHandler;
use MagnaCms\Blog\Support\PostPermalink;
use MagnaCms\Blog\Support\Spam\CommentRejectedAsSpam;

/**
 * The post page's own comment form posts here (web group: session and
 * CSRF, the token filled in by core's form-token endpoint so the form also
 * works on a cached page).
 *
 * Every answer is a redirect back to the post's comments: a notice saying
 * whether the comment is held or shown, or the form's errors. A comment the
 * spam defences refused gets the same "held for moderation" notice a person
 * gets, so a bot learns nothing about which check it tripped.
 */
final class PublicCommentController
{
    /**
     * Where the routes live: a prefix no permalink pattern can produce. The
     * form's URLs are built from it rather than looked up by route name, so
     * a page rendered before the router's name index is refreshed (a plugin
     * enabled mid-request) still renders.
     */
    public const PREFIX = '_blog/comments';

    /** Session flash key the post page reads its notice from. */
    public const NOTICE = 'blog.comment_notice';

    /** Error bag the post page reads the form's errors from. */
    public const ERROR_BAG = 'blogComment';

    public function __construct(private readonly CommentSubmissionHandler $submissions) {}

    /**
     * POST /_blog/comments/{post}
     */
    public function store(Request $request, int $post): RedirectResponse
    {
        $target = $this->findPost($post);
        $back = $this->commentsUrl($target);

        try {
            $comment = $this->submissions->submit($target, $request, CommentChannel::Web);
            $notice = $comment->status === CommentStatus::Approved ? 'approved' : 'pending';
        } catch (CommentRejectedAsSpam) {
            $notice = 'pending';
        } catch (ValidationException $invalid) {
            throw $invalid->redirectTo($back)->errorBag(self::ERROR_BAG);
        }

        return redirect()->to($back)->with(self::NOTICE, $notice);
    }

    /**
     * GET /_blog/comments/{post}/sign-in — the panel login, returning to the
     * post's comments after. "Signed in" for a commenter means a Magna panel
     * user; the site has no separate member system.
     */
    public function signIn(int $post): RedirectResponse
    {
        $target = $this->findPost($post);
        abort_unless(Route::has('filament.magna.auth.login'), 404);

        redirect()->setIntendedUrl(url($this->commentsUrl($target)));

        return redirect()->route('filament.magna.auth.login');
    }

    public static function storePath(int $postId): string
    {
        return '/'.self::PREFIX.'/'.$postId;
    }

    public static function signInPath(int $postId): string
    {
        return self::storePath($postId).'/sign-in';
    }

    private function findPost(int $id): Post
    {
        return Post::query()->live()->public()->whereNull('password')->whereKey($id)->firstOrFail();
    }

    private function commentsUrl(Post $post): string
    {
        return PostPermalink::fromSettings()->url($post).'#comments';
    }
}
