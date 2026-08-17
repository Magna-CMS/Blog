<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Filament\Resources\PostResource;
use MagnaCms\Blog\Filament\Resources\PostResource\Pages\CreatePost;
use MagnaCms\Blog\Filament\Resources\PostResource\Pages\EditPost;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\PostAccess;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
    Filament::setCurrentPanel(Filament::getPanel('magna'));

    Route::get('/_test/posts', fn () => '')->name('filament.magna.resources.posts.index');
    Route::get('/_test/posts/create', fn () => '')->name('filament.magna.resources.posts.create');
    Route::get('/_test/posts/{record}/edit', fn () => '')->name('filament.magna.resources.posts.edit');
});

/** User whose single role grants exactly $permissions (optionally super admin). */
function ownershipUser(array $permissions = [], bool $super = false): User
{
    $role = Role::create(['handle' => 'own-'.uniqid(), 'name' => 'Role', 'is_super_admin' => $super]);
    if ($permissions !== []) {
        $role->grant(...$permissions);
    }

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function postOwnedBy(User $user, array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'Post '.uniqid(),
        'slug' => 'post-'.uniqid(),
        'author_id' => $user->id,
        'status' => 'draft',
    ], $attributes));
}

// ---- PostAccess matrix -----------------------------------------------------

it('grants edit only to the owner or an edit_others holder', function (): void {
    $author = ownershipUser(['blog.posts.edit']);
    $other = ownershipUser(['blog.posts.edit']);
    $editor = ownershipUser(['blog.posts.edit', 'blog.posts.edit-others']);
    $admin = ownershipUser(super: true);

    $post = postOwnedBy($author);

    expect(PostAccess::canEdit($author, $post))->toBeTrue()
        ->and(PostAccess::canEdit($other, $post))->toBeFalse()
        ->and(PostAccess::canEdit($editor, $post))->toBeTrue()
        ->and(PostAccess::canEdit($admin, $post))->toBeTrue();
});

it('requires publish permission AND authority over the post to publish', function (): void {
    $author = ownershipUser(['blog.posts.edit', 'blog.posts.publish']);
    $otherPublisher = ownershipUser(['blog.posts.edit', 'blog.posts.publish']);
    $editor = ownershipUser(['blog.posts.edit', 'blog.posts.publish', 'blog.posts.edit-others']);
    $noPublish = ownershipUser(['blog.posts.edit']);

    $post = postOwnedBy($author);

    expect(PostAccess::canPublish($author, $post))->toBeTrue()          // owner + publish
        ->and(PostAccess::canPublish($otherPublisher, $post))->toBeFalse() // publish but not owner
        ->and(PostAccess::canPublish($editor, $post))->toBeTrue()          // edit_others + publish
        ->and(PostAccess::canPublish($noPublish, $post))->toBeFalse();     // owner but no publish
});

it('gates delete and restore on permission plus ownership', function (): void {
    $author = ownershipUser(['blog.posts.delete', 'blog.posts.restore']);
    $other = ownershipUser(['blog.posts.delete', 'blog.posts.restore']);
    $editor = ownershipUser(['blog.posts.delete', 'blog.posts.restore', 'blog.posts.edit-others']);

    $post = postOwnedBy($author);

    expect(PostAccess::canDelete($author, $post))->toBeTrue()
        ->and(PostAccess::canDelete($other, $post))->toBeFalse()
        ->and(PostAccess::canDelete($editor, $post))->toBeTrue()
        ->and(PostAccess::canRestore($other, $post))->toBeFalse()
        ->and(PostAccess::canRestore($editor, $post))->toBeTrue();
});

// ---- Gate policy (idiomatic surface over the same rule) --------------------

it('exposes post authorization through the Gate policy, delegating to PostAccess', function (): void {
    $author = ownershipUser(['blog.posts.view', 'blog.posts.edit', 'blog.posts.publish', 'blog.posts.delete', 'blog.posts.restore']);
    $other = ownershipUser(['blog.posts.view', 'blog.posts.edit', 'blog.posts.publish', 'blog.posts.delete', 'blog.posts.restore']);
    $post = postOwnedBy($author);

    // can(...) goes through PostPolicy, which delegates to PostAccess — so the
    // idiomatic Gate call and the helper must always agree, and ownership holds.
    expect($author->can('update', $post))->toBe(PostAccess::canEdit($author, $post))->toBeTrue()
        ->and($other->can('update', $post))->toBe(PostAccess::canEdit($other, $post))->toBeFalse()
        ->and($author->can('publish', $post))->toBe(PostAccess::canPublish($author, $post))->toBeTrue()
        ->and($other->can('publish', $post))->toBeFalse()
        ->and($other->can('delete', $post))->toBeFalse()
        ->and($other->can('view', $post))->toBeFalse();      // another author's unpublished draft
});

// ---- Filament resource abilities ------------------------------------------

it('exposes ownership-aware edit/delete on the resource', function (): void {
    $author = ownershipUser(['blog.posts.view', 'blog.posts.edit', 'blog.posts.delete']);
    $post = postOwnedBy($author);
    $othersPost = postOwnedBy(ownershipUser(['blog.posts.edit']));

    $this->actingAs($author);

    expect(PostResource::canEdit($post))->toBeTrue()
        ->and(PostResource::canEdit($othersPost))->toBeFalse()
        ->and(PostResource::canDelete($othersPost))->toBeFalse();
});

// ---- Livewire end-to-end ---------------------------------------------------

it('blocks an author from opening another author authors post in the builder', function (): void {
    $authorA = ownershipUser(['blog.posts.view', 'blog.posts.edit']);
    $postB = postOwnedBy(ownershipUser(['blog.posts.edit']));

    $this->actingAs($authorA);

    expect(PostAccess::canEdit($authorA, $postB))->toBeFalse();

    // Opening another author's post is blocked at mount with a 403.
    Livewire::test(EditPost::class, ['record' => $postB->getKey()])
        ->assertForbidden();
});

it('lets an author autosave their own post', function (): void {
    $author = ownershipUser(['blog.posts.view', 'blog.posts.edit']);
    $post = postOwnedBy($author, ['title' => 'Mine']);

    $this->actingAs($author);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Mine edited')
        ->call('autosave');

    expect($post->fresh()->title)->toBe('Mine edited');
});

it('lets an edit_others editor edit another author post', function (): void {
    $authorA = ownershipUser(['blog.posts.edit']);
    $editor = ownershipUser(['blog.posts.view', 'blog.posts.edit', 'blog.posts.edit-others']);
    $post = postOwnedBy($authorA, ['title' => 'By A']);

    $this->actingAs($editor);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'By A, edited by editor')
        ->call('autosave');

    expect($post->fresh()->title)->toBe('By A, edited by editor')
        // Editing must never steal authorship.
        ->and((string) $post->fresh()->author_id)->toBe((string) $authorA->id);
});

it('ignores a forged author_id in the autosave payload', function (): void {
    $author = ownershipUser(['blog.posts.view', 'blog.posts.edit']);
    $victim = ownershipUser(['blog.posts.edit']);
    $post = postOwnedBy($author);

    $this->actingAs($author);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Tamper attempt')
        ->set('data.author_id', $victim->id)
        ->call('autosave');

    expect((string) $post->fresh()->author_id)->toBe((string) $author->id);
});

it('refuses deletePost without the delete permission', function (): void {
    // Has edit (can open + autosave) but NOT delete: the builder delete button
    // action must still refuse server-side.
    $author = ownershipUser(['blog.posts.view', 'blog.posts.edit']);
    $post = postOwnedBy($author);

    $this->actingAs($author);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->call('deletePost')
        ->assertStatus(403);

    expect($post->fresh())->not->toBeNull();
});

it('lets an owner with delete permission trash their post', function (): void {
    $author = ownershipUser(['blog.posts.view', 'blog.posts.edit', 'blog.posts.delete']);
    $post = postOwnedBy($author);

    $this->actingAs($author);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->call('deletePost');

    expect(Post::query()->find($post->getKey()))->toBeNull()
        ->and(Post::withTrashed()->find($post->getKey())->trashed())->toBeTrue();
});

it('does not let the create page overwrite another author post via a forged draftId', function (): void {
    // An ordinary contributor: can create + edit their own posts, nothing more.
    $attacker = ownershipUser(['blog.posts.view', 'blog.posts.create', 'blog.posts.edit']);
    $victimPost = postOwnedBy(ownershipUser(['blog.posts.edit']), ['title' => 'Victim original']);

    $this->actingAs($attacker);

    // draftId is a public Livewire property, so a crafted request can point the
    // create page at any post id. Autosave is the un-gated save path; the server
    // must refuse rather than update (and reassign authorship of) the victim post.
    Livewire::test(CreatePost::class)
        ->set('data.title', 'Hijacked')
        ->set('draftId', $victimPost->getKey())
        ->call('autosave')
        ->assertStatus(403);

    $fresh = $victimPost->fresh();
    expect($fresh->title)->toBe('Victim original')
        ->and((string) $fresh->author_id)->not->toBe((string) $attacker->id);
});

it('does not let an edit_others user seize authorship via a forged draftId', function (): void {
    // User B holds edit-others, so persist()'s ownership gate lets the write
    // through — but editing another author's post must never transfer authorship.
    $authorA = ownershipUser(['blog.posts.edit']);
    $editorB = ownershipUser(['blog.posts.view', 'blog.posts.create', 'blog.posts.edit', 'blog.posts.edit-others']);
    $victimPost = postOwnedBy($authorA, ['title' => 'A original']);

    $this->actingAs($editorB);

    // Content change is allowed (edit-others); author_id must stay User A.
    Livewire::test(CreatePost::class)
        ->set('data.title', 'Edited by B')
        ->set('draftId', $victimPost->getKey())
        ->call('autosave');

    $fresh = $victimPost->fresh();
    expect($fresh->title)->toBe('Edited by B')
        ->and((string) $fresh->author_id)->toBe((string) $authorA->id)
        ->and((string) $fresh->author_id)->not->toBe((string) $editorB->id)
        // User A keeps every ownership-derived right; User B is not the author.
        ->and(PostAccess::owns($authorA, $fresh))->toBeTrue()
        ->and(PostAccess::owns($editorB, $fresh))->toBeFalse();
});

it('ignores a forged author_id on the create-page persistence path', function (): void {
    // A brand-new draft must be stamped with the creator's id regardless of any
    // author_id injected into the public data bag.
    $creator = ownershipUser(['blog.posts.view', 'blog.posts.create', 'blog.posts.edit']);
    $victim = ownershipUser(['blog.posts.edit']);

    $this->actingAs($creator);

    Livewire::test(CreatePost::class)
        ->set('data.title', 'New draft')
        ->set('data.author_id', $victim->id)
        ->call('autosave');

    $post = Post::query()->where('title', 'New draft')->firstOrFail();
    expect((string) $post->author_id)->toBe((string) $creator->id)
        ->and((string) $post->author_id)->not->toBe((string) $victim->id);
});

it('lets the create page keep autosaving its own draft across saves', function (): void {
    // The ownership guard on the update path must not break the normal
    // create-then-autosave loop, where draftId points at the user's own draft.
    $author = ownershipUser(['blog.posts.view', 'blog.posts.create', 'blog.posts.edit']);
    $this->actingAs($author);

    $component = Livewire::test(CreatePost::class)
        ->set('data.title', 'My draft')
        ->call('autosave');

    $post = Post::query()->where('title', 'My draft')->firstOrFail();

    // Second autosave updates the SAME record (draftId is now the author's own
    // post id, set server-side on the first save).
    $component->set('data.title', 'My draft v2')->call('autosave');

    expect($post->fresh()->title)->toBe('My draft v2')
        ->and(Post::query()->where('author_id', $author->id)->count())->toBe(1);
});

it('lets a super admin edit any post', function (): void {
    $admin = ownershipUser(super: true);
    $post = postOwnedBy(ownershipUser(['blog.posts.edit']), ['title' => 'Someone else']);

    $this->actingAs($admin);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Admin edited')
        ->call('autosave');

    expect($post->fresh()->title)->toBe('Admin edited');
});
