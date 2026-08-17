<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support\Spam;

/**
 * Contract for comment spam detection. Implementations return true when a
 * submission looks like spam and should be rejected. Bound in the container so
 * the driver (honeypot, Akismet, …) is swappable without touching the controller.
 */
interface SpamCheck
{
    public function isSpam(SpamSubmission $submission): bool;
}
