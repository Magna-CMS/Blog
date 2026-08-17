<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support\Spam;

/**
 * Zero-dependency spam check: rejects a submission when the honeypot field was
 * filled (a hidden field only a bot completes) or when the form was submitted
 * implausibly fast (faster than the configured minimum, which only a script
 * achieves). Both signals are opt-in from the client but enforced server-side
 * whenever present, so they add no false positives for a normal visitor.
 */
class HoneypotSpamCheck implements SpamCheck
{
    public function __construct(private readonly int $minSubmitSeconds = 2) {}

    public function isSpam(SpamSubmission $submission): bool
    {
        if (trim($submission->honeypot) !== '') {
            return true;
        }

        if ($this->minSubmitSeconds > 0
            && $submission->elapsedSeconds !== null
            && $submission->elapsedSeconds < $this->minSubmitSeconds) {
            return true;
        }

        return false;
    }
}
