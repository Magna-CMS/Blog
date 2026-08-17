<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Enums;

/**
 * Lifecycle state of a post. "Trash" is represented separately by the model's
 * soft-delete (deleted_at), not by a status value, so a post retains its prior
 * status when trashed and can be restored to it.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Scheduled = 'scheduled';
    case Archived = 'archived';

    public function label(): string
    {
        return (string) __('blog::enums.post_status.'.$this->value);
    }

    /**
     * Options map for Filament selects: value => label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
