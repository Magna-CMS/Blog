<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support\Spam;

/**
 * Immutable snapshot of a comment submission passed to spam checks. Carries both
 * the content and the anti-bot signals (honeypot value, how long the form was on
 * screen, client IP/user agent) so a driver can decide without touching the
 * request directly.
 */
final class SpamSubmission
{
    public function __construct(
        public readonly string $body,
        public readonly string $authorName,
        public readonly string $authorEmail,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly string $honeypot = '',
        public readonly ?int $elapsedSeconds = null,
    ) {}
}
