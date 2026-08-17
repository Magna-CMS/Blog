<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support\Spam;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Akismet content scoring on top of the honeypot. The honeypot runs first (cheap,
 * offline); only if it passes does the submission go to Akismet's comment-check
 * endpoint. Fails open — if no key is configured or the API errors, the comment
 * is NOT treated as spam, so a misconfiguration never silently drops real
 * comments (moderation is still the backstop).
 */
class AkismetSpamCheck implements SpamCheck
{
    public function __construct(
        private readonly HoneypotSpamCheck $honeypot,
        private readonly ?string $key,
        private readonly ?string $site,
    ) {}

    public function isSpam(SpamSubmission $submission): bool
    {
        if ($this->honeypot->isSpam($submission)) {
            return true;
        }

        if ($this->key === null || $this->key === '' || $this->site === null || $this->site === '') {
            return false; // Not configured: honeypot-only.
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post("https://{$this->key}.rest.akismet.com/1.1/comment-check", [
                    'blog' => $this->site,
                    'user_ip' => $submission->ip ?? '',
                    'user_agent' => $submission->userAgent ?? '',
                    'comment_type' => 'comment',
                    'comment_author' => $submission->authorName,
                    'comment_author_email' => $submission->authorEmail,
                    'comment_content' => $submission->body,
                ]);

            return trim($response->body()) === 'true';
        } catch (\Throwable $e) {
            // Log the failure type only — never $e->getMessage(), which for a
            // network error embeds the request URL and therefore the API key that
            // rides in the Akismet hostname ({key}.rest.akismet.com).
            Log::warning('Akismet spam check failed; treating comment as clean.', ['exception' => $e::class]);

            return false;
        }
    }
}
