<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

/**
 * Which front door a comment came through. The rules are shared
 * (CommentSubmissionHandler); these are the only places they differ.
 */
enum CommentChannel
{
    /**
     * The delivery API: a headless frontend posting JSON with a delivery
     * token. It owns its visitors' identity and its login rule, and keeps
     * the 1.x contract: the unsigned `form_started_at` still counts, a
     * signed `_ts` is checked when sent, spam is a 422.
     */
    case Api;

    /**
     * The public comment form on the post page: the signed `_ts` render
     * stamp is required, a signed-in panel user is the author, the
     * require-login setting is enforced, spam is discarded without a word.
     */
    case Web;
}
