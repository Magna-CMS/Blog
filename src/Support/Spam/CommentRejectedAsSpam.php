<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support\Spam;

use Illuminate\Validation\ValidationException;

/**
 * A comment the spam defences refused. A ValidationException, so the
 * delivery API answers it exactly as it always has (422, a generic message
 * that names no check); its own type, so the web form can tell it from a
 * real mistake and answer a bot as if the comment had been accepted.
 */
final class CommentRejectedAsSpam extends ValidationException
{
    public static function make(): self
    {
        return self::withMessages(['body' => 'Your comment could not be submitted.']);
    }
}
