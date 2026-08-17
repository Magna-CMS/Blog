<?php

declare(strict_types=1);

use Magna\Auth\Role;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\PostWorkflow;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

/** A user whose single role grants exactly the given blog permissions. */
function userWith(array $permissions): User
{
    $role = Role::create(['handle' => 'r-'.uniqid(), 'name' => 'Test role']);
    $role->grant(...$permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('excludes pending-review posts from the delivery scope', function (): void {
    Post::create([
        'title' => 'Pending', 'slug' => 'pending', 'content' => ['blocks' => []],
        'status' => 'pending_review', 'visibility' => 'public', 'published_at' => now()->subDay(),
    ]);

    expect(Post::query()->live()->public()->count())->toBe(0);
});

it('gates publishing on the blog.posts.publish permission', function (): void {
    $reviewer = userWith(['blog.posts.view', 'blog.posts.edit', 'blog.posts.publish']);
    $contributor = userWith(['blog.posts.view', 'blog.posts.edit']);

    expect($reviewer->can('blog.posts.publish'))->toBeTrue()
        ->and($contributor->can('blog.posts.publish'))->toBeFalse();
});

it('records an incoming note only when sending back to draft', function (): void {
    // Send back to draft with a note.
    expect(PostWorkflow::reviewNote(PostStatus::Draft->value, 'Add sources', null))
        ->toBe('Add sources');

    // Plain draft save keeps the prior note.
    expect(PostWorkflow::reviewNote(PostStatus::Draft->value, null, 'prior feedback'))
        ->toBe('prior feedback');

    // Blank/whitespace incoming note does not overwrite the prior note.
    expect(PostWorkflow::reviewNote(PostStatus::Draft->value, '   ', 'prior feedback'))
        ->toBe('prior feedback');
});

it('clears the note on submission and publication', function (): void {
    expect(PostWorkflow::reviewNote(PostStatus::PendingReview->value, null, 'old feedback'))
        ->toBeNull()
        ->and(PostWorkflow::reviewNote(PostStatus::Published->value, null, 'old feedback'))
        ->toBeNull();
});

it('trims a stored note', function (): void {
    expect(PostWorkflow::reviewNote(PostStatus::Draft->value, '  spaced  ', null))
        ->toBe('spaced');
});

it('lets a publisher set any status', function (): void {
    foreach (PostStatus::cases() as $case) {
        expect(PostWorkflow::resolveStatus(null, $case->value, canPublish: true, current: null))
            ->toBe($case->value);
    }
});

it('refuses a publish-tier status from a user without publish permission', function (): void {
    // Creating (no current status): a privileged request collapses to draft.
    foreach (['published', 'scheduled', 'archived'] as $privileged) {
        expect(PostWorkflow::resolveStatus(null, $privileged, canPublish: false, current: null))
            ->toBe('draft');
    }

    // A forged forcedStatus property is clamped exactly the same way.
    expect(PostWorkflow::resolveStatus('published', null, canPublish: false, current: null))
        ->toBe('draft');
});

it('allows a non-publisher to set draft or pending review', function (): void {
    expect(PostWorkflow::resolveStatus(null, 'draft', canPublish: false, current: null))->toBe('draft')
        ->and(PostWorkflow::resolveStatus(null, 'pending_review', canPublish: false, current: null))->toBe('pending_review');
});

it('never silently unpublishes a live post when a non-publisher saves it', function (): void {
    // Editing an already-published post: a privileged incoming status keeps the
    // post's current (published) status rather than downgrading it.
    expect(PostWorkflow::resolveStatus(null, 'published', canPublish: false, current: 'published'))
        ->toBe('published');
});

it('coerces an unknown status to draft', function (): void {
    expect(PostWorkflow::resolveStatus(null, 'wat', canPublish: true, current: null))->toBe('draft')
        ->and(PostWorkflow::resolveStatus(null, ['array'], canPublish: true, current: null))->toBe('draft');
});
