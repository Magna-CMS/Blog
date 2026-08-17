<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use MagnaCms\Blog\Enums\PostStatus;

/**
 * Pure decisions for the editorial workflow, kept out of the Livewire pages so
 * the transition rules are unit-testable without a booted Filament panel.
 */
final class PostWorkflow
{
    /**
     * Statuses a user WITHOUT the `blog.posts.publish` permission may set on their
     * own. Everything else (Published, Scheduled, Archived) is a privileged,
     * publish-tier state and is refused for such users.
     *
     * @var list<string>
     */
    private const SELF_SERVICE = [
        PostStatus::Draft->value,
        PostStatus::PendingReview->value,
    ];

    /**
     * Clamp the status a save is allowed to persist to what the current user may
     * actually set. This is the server-side authority behind the publish gate:
     * the sidebar status <select>, the `forcedStatus` public property and any
     * forged Livewire payload all flow through here, so a user without
     * `blog.posts.publish` can never move a post into a publish-tier state
     * (Published / Scheduled / Archived) — nor accidentally downgrade one that is
     * already there (their edit keeps the post's current status instead).
     *
     * @param  string|null  $forced  Status forced by an authorised action button (wins when set).
     * @param  mixed  $requested  Status coming from the (client-controlled) form state.
     * @param  bool  $canPublish  Whether the user holds blog.posts.publish.
     * @param  string|null  $current  The post's currently-persisted status (null on create).
     */
    public static function resolveStatus(?string $forced, mixed $requested, bool $canPublish, ?string $current): string
    {
        $chosen = $forced !== null && $forced !== ''
            ? $forced
            : (is_string($requested) && $requested !== '' ? $requested : PostStatus::Draft->value);

        // Reject anything that is not a known status outright.
        if (PostStatus::tryFrom($chosen) === null) {
            $chosen = PostStatus::Draft->value;
        }

        if ($canPublish || in_array($chosen, self::SELF_SERVICE, true)) {
            return $chosen;
        }

        // A privileged status was requested without permission: keep whatever the
        // post already is (so a live post is never silently unpublished), falling
        // back to draft on create.
        return $current ?? PostStatus::Draft->value;
    }

    /**
     * Resolve the review note to store for a transition into $newStatus:
     * - sending back to draft with an incoming note records that note;
     * - any other draft save keeps whatever note the post already had;
     * - (re)submission and publishing clear the note so stale feedback never lingers.
     */
    public static function reviewNote(string $newStatus, ?string $incomingNote, ?string $currentNote): ?string
    {
        if ($newStatus !== PostStatus::Draft->value) {
            return null;
        }

        $incoming = $incomingNote !== null ? trim($incomingNote) : '';

        return $incoming !== '' ? $incoming : $currentNote;
    }
}
