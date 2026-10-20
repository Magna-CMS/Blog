<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Magna\Auth\Captcha\Rules\Captcha;
use Magna\Frontend\Forms\SignedFormTimestamp;
use Magna\Users\User;
use MagnaCms\Blog\Enums\CommentStatus;
use MagnaCms\Blog\Models\Comment;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\Spam\CommentRejectedAsSpam;
use MagnaCms\Blog\Support\Spam\SpamCheck;
use MagnaCms\Blog\Support\Spam\SpamSubmission;

/**
 * Turns a comment submission into a stored comment — the one path both the
 * delivery API and the public comment form take, so the rules cannot drift
 * between them:
 *
 *  1. the post must be open to comments (CommentPolicy);
 *  2. on the web form, "require login" means a signed-in Magna panel user —
 *     there is no frontend member system — and that user is the author:
 *     name, email and id come from the account, never from the form;
 *  3. bots are refused by the honeypot, the signed render stamp (`_ts`,
 *     SignedFormTimestamp: genuine, for this form, and old enough that a
 *     person could have typed), the captcha surface when a captcha plugin
 *     enables it, and the configured spam check;
 *  4. a reply to a reply is filed under its top-level comment — the thread
 *     is one level deep everywhere it is shown;
 *  5. text is stored as text (tags stripped) and moderated per settings.
 *
 * Throws ValidationException for a mistake a person can fix, and
 * CommentRejectedAsSpam (also a ValidationException) for a bot.
 */
final class CommentSubmissionHandler
{
    /** SignedFormTimestamp purpose: a stamp from another plugin's form does not pass here. */
    public const PURPOSE = 'blog-comments';

    /** The captcha surface key the form renders and verifies against. */
    public const CAPTCHA_SURFACE = 'blog.comments';

    public const MAX_BODY = 5000;

    public function __construct(
        private readonly CommentPolicy $policy,
        private readonly SpamCheck $spam,
    ) {}

    /** The request field bots fill and people never see. */
    public static function honeypotField(): string
    {
        return (string) config('blog.comments.honeypot_field', 'website');
    }

    /**
     * @throws ValidationException
     */
    public function submit(Post $post, Request $request, CommentChannel $channel): Comment
    {
        if (! $this->policy->open($post)) {
            throw ValidationException::withMessages(['body' => 'Comments are closed for this post.']);
        }

        $user = $request->user();
        $author = $channel === CommentChannel::Web && $user instanceof User ? $user : null;

        if ($channel === CommentChannel::Web && $author === null && $this->policy->requiresLogin()) {
            throw ValidationException::withMessages(['body' => 'Sign in to comment on this post.']);
        }

        $data = $request->validate($this->rules($channel, $author));

        $body = self::text($data['body'] ?? null);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write a comment before posting it.']);
        }

        $name = $author !== null ? (string) $author->name : self::text($data['author_name'] ?? null);
        $email = $author !== null ? (string) $author->email : trim($request->string('author_email')->toString());

        if (! $this->stampPasses($request, $channel) || $this->spam->isSpam(new SpamSubmission(
            body: $body,
            authorName: $name,
            authorEmail: $email,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            honeypot: $request->string(self::honeypotField())->toString(),
            elapsedSeconds: $this->legacyElapsedSeconds($request, $channel),
        ))) {
            throw CommentRejectedAsSpam::make();
        }

        $approved = BlogSettings::get()->comment_moderation === 'auto';

        return Comment::create([
            'post_id' => $post->id,
            'parent_id' => $this->topLevelParent($post, $data['parent_id'] ?? null),
            'author_id' => $author?->getKey(),
            'author_name' => $name,
            'author_email' => $email,
            'body' => $body,
            'status' => $approved ? CommentStatus::Approved->value : CommentStatus::Pending->value,
        ]);
    }

    /** Submitted text as plain text: markup stripped, edges trimmed. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(strip_tags($value)) : '';
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(CommentChannel $channel, ?User $author): array
    {
        $rules = [
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            'parent_id' => ['nullable', 'integer'],
            SignedFormTimestamp::FIELD => ['nullable', 'string', 'max:128'],
            self::honeypotField() => ['nullable', 'string', 'max:255'],
        ];

        if ($author === null) {
            $rules['author_name'] = ['required', 'string', 'max:120'];
            $rules['author_email'] = ['required', 'email', 'max:190'];
        }

        if ($channel === CommentChannel::Api) {
            $rules['form_started_at'] = ['nullable', 'integer'];
        }

        // Core's captcha rule passes when no captcha plugin is bound, or the
        // surface is switched off — which it is until a site owner turns it on.
        if ($channel === CommentChannel::Web && class_exists(Captcha::class)) {
            $rules['captcha_token'] = ['nullable', 'string', new Captcha(self::CAPTCHA_SURFACE)];
        }

        return $rules;
    }

    /**
     * The signed render stamp: required on the web form, checked on the API
     * whenever a client sends one.
     */
    private function stampPasses(Request $request, CommentChannel $channel): bool
    {
        $stamp = $request->input(SignedFormTimestamp::FIELD);

        if ($channel === CommentChannel::Api && ($stamp === null || $stamp === '')) {
            return true;
        }

        return SignedFormTimestamp::passes($stamp, self::PURPOSE, (int) config('blog.comments.min_submit_seconds', 2));
    }

    /**
     * The 1.x API's unsigned `form_started_at`, still honoured there for its
     * existing clients. It can only make a submission look MORE like a bot —
     * a client lying about it gains nothing the stamp-less API did not
     * already allow — so keeping it costs nothing. Never read on the web.
     */
    private function legacyElapsedSeconds(Request $request, CommentChannel $channel): ?int
    {
        if ($channel !== CommentChannel::Api) {
            return null;
        }

        $startedAt = $request->integer('form_started_at');

        return $startedAt > 0 ? max(0, now()->getTimestamp() - $startedAt) : null;
    }

    /**
     * A reply's parent must be an approved comment on the same post; anything
     * else is a top-level comment. A reply to a reply is filed under that
     * reply's own parent, so every thread stays one level deep.
     */
    private function topLevelParent(Post $post, mixed $parentId): ?int
    {
        if (! is_numeric($parentId)) {
            return null;
        }

        $parent = Comment::query()
            ->whereKey((int) $parentId)
            ->where('post_id', $post->id)
            ->approved()
            ->first(['id', 'parent_id']);

        return $parent === null ? null : ($parent->parent_id ?? $parent->id);
    }
}
