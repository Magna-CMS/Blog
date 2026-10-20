<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Frontend;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Magna\Frontend\Forms\SignedFormTimestamp;
use MagnaCms\Blog\Http\Controllers\PublicCommentController;
use MagnaCms\Blog\Models\Comment;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\CommentPolicy;
use MagnaCms\Blog\Support\CommentSubmissionHandler;

/**
 * Everything the post page's comments section shows, resolved up front so
 * the view never queries: the approved thread (top-level comments oldest
 * first, each with its approved replies), whether the form is open, and
 * what the form needs to work on a cached page.
 *
 * Capped at MAX_COMMENTS top-level comments and MAX_REPLIES replies in all:
 * a post page is rendered for every visitor, and a thread that grows
 * without bound is a page that does too.
 *
 * @phpstan-type CommentView array{id: int, author: string, date: CarbonInterface|null, paragraphs: list<list<string>>, replies: list<array<string, mixed>>}
 */
final class PostCommentThread
{
    public const MAX_COMMENTS = 100;

    public const MAX_REPLIES = 500;

    /** Where the captcha plugin's form widget lives, when it is installed. */
    private const CAPTCHA_VIEW = 'defence::components.captcha';

    public function __construct(private readonly CommentPolicy $policy) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Post $post, Request $request, bool $signedIn): array
    {
        $items = $this->items($post);
        $open = $this->policy->open($post);

        return [
            // A post closed to comments that has none renders no section.
            'visible' => $open || $items !== [],
            'open' => $open,
            'signedIn' => $signedIn,
            'loginRequired' => ! $signedIn && $this->policy->requiresLogin(),
            'items' => $items,
            'count' => count($items) + array_sum(array_map(fn (array $item): int => count($item['replies']), $items)),
            'action' => PublicCommentController::storePath($post->id),
            'signInUrl' => PublicCommentController::signInPath($post->id),
            'timestamp' => SignedFormTimestamp::issue(CommentSubmissionHandler::PURPOSE),
            'honeypot' => CommentSubmissionHandler::honeypotField(),
            'captchaView' => View::exists(self::CAPTCHA_VIEW) ? self::CAPTCHA_VIEW : null,
            'captchaSurface' => CommentSubmissionHandler::CAPTCHA_SURFACE,
            'notice' => $this->notice($request),
            'errors' => $this->errors($request),
            'old' => $this->old($request),
        ];
    }

    /** @return list<CommentView> */
    private function items(Post $post): array
    {
        $top = Comment::query()
            ->where('post_id', $post->id)
            ->approved()
            ->whereNull('parent_id')
            ->with('author:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_COMMENTS)
            ->get();

        if ($top->isEmpty()) {
            return [];
        }

        $replies = Comment::query()
            ->where('post_id', $post->id)
            ->approved()
            ->whereIn('parent_id', $top->modelKeys())
            ->with('author:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_REPLIES)
            ->get()
            ->groupBy('parent_id');

        $items = [];
        foreach ($top as $comment) {
            $item = $this->present($comment);
            $item['replies'] = array_map(
                fn (Comment $reply): array => $this->present($reply),
                array_values($replies->get($comment->id)?->all() ?? []),
            );
            $items[] = $item;
        }

        return $items;
    }

    /** @return CommentView */
    private function present(Comment $comment): array
    {
        $account = $comment->author_id !== null ? $comment->author : null;
        $name = trim((string) ($account->name ?? $comment->author_name ?? ''));

        return [
            'id' => (int) $comment->id,
            'author' => $name !== '' ? $name : 'Anonymous',
            'date' => $comment->created_at,
            'paragraphs' => self::paragraphs($comment->body),
            'replies' => [],
        ];
    }

    /**
     * The comment as paragraphs of lines — plain text the view escapes and
     * lays out, so a commenter's line breaks survive without any of their
     * markup being trusted.
     *
     * @return list<list<string>>
     */
    private static function paragraphs(string $body): array
    {
        $paragraphs = [];
        foreach (preg_split('/\R{2,}/u', trim($body)) ?: [] as $block) {
            $lines = array_values(array_filter(
                array_map('trim', preg_split('/\R/u', $block) ?: []),
                static fn (string $line): bool => $line !== '',
            ));
            if ($lines !== []) {
                $paragraphs[] = $lines;
            }
        }

        return $paragraphs;
    }

    private function notice(Request $request): ?string
    {
        $notice = $request->hasSession() ? $request->session()->get(PublicCommentController::NOTICE) : null;

        return in_array($notice, ['pending', 'approved'], true) ? $notice : null;
    }

    /** @return list<string> */
    private function errors(Request $request): array
    {
        $bags = $request->hasSession() ? $request->session()->get('errors') : null;

        return $bags instanceof ViewErrorBag
            ? array_values(array_map('strval', $bags->getBag(PublicCommentController::ERROR_BAG)->all()))
            : [];
    }

    /** @return array{author_name: string, author_email: string, body: string, parent_id: string} */
    private function old(Request $request): array
    {
        $old = fn (string $key): string => $request->hasSession() && is_string($value = $request->session()->getOldInput($key)) ? $value : '';

        return [
            'author_name' => $old('author_name'),
            'author_email' => $old('author_email'),
            'body' => $old('body'),
            'parent_id' => $old('parent_id'),
        ];
    }
}
