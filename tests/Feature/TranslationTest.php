<?php

declare(strict_types=1);

use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Enums\CommentStatus;
use MagnaCms\Blog\Enums\MetaType;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

it('registers the blog translation namespace when the plugin boots', function (): void {
    // A resolved key (rather than the key echoed back) proves loadTranslationsFrom
    // registered the `blog` namespace against the plugin's lang directory.
    expect(__('blog::resources.post.plural'))->toBe('Posts')
        ->and(__('blog::enums.post_status.published'))->toBe('Published')
        ->and(__('blog::builder.notifications.draft_saved'))->toBe('Draft saved')
        ->and(__('blog::settings.title'))->toBe('Blog Settings');
});

it('resolves enum labels through the translator', function (): void {
    expect(PostStatus::Published->label())->toBe('Published')
        ->and(PostStatus::PendingReview->label())->toBe('Pending review')
        ->and(PostVisibility::Password->label())->toBe('Password protected')
        ->and(CommentStatus::Spam->label())->toBe('Spam')
        ->and(MetaType::Boolean->label())->toBe('True / false');
});

it('keeps every enum option label translatable and non-empty', function (): void {
    foreach ([PostStatus::class, PostVisibility::class, CommentStatus::class, MetaType::class] as $enum) {
        foreach ($enum::options() as $value => $label) {
            expect($label)->toBeString()->not->toBe('')
                // A missing key echoes the dotted key back; assert it was resolved.
                ->and($label)->not->toContain('blog::');
        }
    }
});
