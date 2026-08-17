<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Filament\Resources\PostResource\Pages\CreatePost;
use MagnaCms\Blog\Filament\Resources\PostResource\Pages\EditPost;
use MagnaCms\Blog\Filament\Resources\PostResource\Pages\ListPosts;
use MagnaCms\Blog\Models\Post;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
    Filament::setCurrentPanel(Filament::getPanel('magna'));

    // The full-screen builder view links back to the posts index; register the
    // Filament resource route name so the component can render under test (the
    // panel's resource routes are not booted in the plugin test harness).
    Route::get('/_test/posts', fn () => '')->name('filament.magna.resources.posts.index');
    Route::get('/_test/posts/create', fn () => '')->name('filament.magna.resources.posts.create');
    Route::get('/_test/posts/{record}/edit', fn () => '')->name('filament.magna.resources.posts.edit');
});

/** A user whose single role grants exactly the given blog permissions. */
function blogUserWith(array $permissions): User
{
    $role = Role::create(['handle' => 'pa-'.uniqid(), 'name' => 'Test role']);
    $role->grant(...$permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('does not let an editor without publish permission publish via the status select', function (): void {
    $contributor = blogUserWith(['blog.posts.view', 'blog.posts.create', 'blog.posts.edit']);
    $this->actingAs($contributor);

    // Autosave is the un-gated save path; a forged/selected "published" status
    // must be refused server-side and collapse to draft.
    Livewire::test(CreatePost::class)
        ->set('data.title', 'Sneaky publish')
        ->set('data.status', PostStatus::Published->value)
        ->call('autosave');

    $post = Post::query()->where('title', 'Sneaky publish')->firstOrFail();
    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->published_at)->toBeNull();
});

it('does not let a non-publisher schedule via the status select', function (): void {
    $contributor = blogUserWith(['blog.posts.view', 'blog.posts.create', 'blog.posts.edit']);
    $this->actingAs($contributor);

    Livewire::test(CreatePost::class)
        ->set('data.title', 'Sneaky schedule')
        ->set('data.status', PostStatus::Scheduled->value)
        ->call('autosave');

    expect(Post::query()->where('title', 'Sneaky schedule')->firstOrFail()->status)
        ->toBe(PostStatus::Draft);
});

it('rejects a forged forcedStatus property from a non-publisher', function (): void {
    $contributor = blogUserWith(['blog.posts.view', 'blog.posts.create', 'blog.posts.edit']);
    $this->actingAs($contributor);

    // forcedStatus is a public Livewire property: a crafted request can set it.
    Livewire::test(CreatePost::class)
        ->set('data.title', 'Forged force')
        ->set('forcedStatus', PostStatus::Published->value)
        ->call('autosave');

    expect(Post::query()->where('title', 'Forged force')->firstOrFail()->status)
        ->toBe(PostStatus::Draft);
});

it('lets a publisher publish via the status select', function (): void {
    $publisher = blogUserWith(['blog.posts.view', 'blog.posts.create', 'blog.posts.edit', 'blog.posts.publish']);
    $this->actingAs($publisher);

    Livewire::test(CreatePost::class)
        ->set('data.title', 'Legit publish')
        ->set('data.status', PostStatus::Published->value)
        ->call('autosave');

    $post = Post::query()->where('title', 'Legit publish')->firstOrFail();
    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->published_at)->not->toBeNull();
});

it('does not let a non-publisher move an existing draft to published on edit', function (): void {
    $contributor = blogUserWith(['blog.posts.view', 'blog.posts.edit']);
    $post = Post::create(['title' => 'Draft post', 'slug' => 'draft-post', 'status' => 'draft']);

    $this->actingAs($contributor);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.status', PostStatus::Published->value)
        ->call('autosave');

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

it('does not let a non-publisher feature a post on create', function (): void {
    $contributor = blogUserWith(['blog.posts.view', 'blog.posts.create', 'blog.posts.edit']);
    $this->actingAs($contributor);

    Livewire::test(CreatePost::class)
        ->set('data.title', 'Wannabe featured')
        ->set('data.is_featured', true)
        ->call('autosave');

    expect(Post::query()->where('title', 'Wannabe featured')->firstOrFail()->is_featured)
        ->toBeFalse();
});

it('lets a publisher feature a post on create', function (): void {
    $publisher = blogUserWith(['blog.posts.view', 'blog.posts.create', 'blog.posts.edit', 'blog.posts.publish']);
    $this->actingAs($publisher);

    Livewire::test(CreatePost::class)
        ->set('data.title', 'Truly featured')
        ->set('data.is_featured', true)
        ->call('autosave');

    expect(Post::query()->where('title', 'Truly featured')->firstOrFail()->is_featured)
        ->toBeTrue();
});

it('does not let a non-publisher feature a post via the list toggle column', function (): void {
    // Owns the post and can edit it, but has no publish permission — so featuring
    // (a publish-tier act) must be refused even through the inline toggle column,
    // which otherwise runs no record policy of its own.
    $author = blogUserWith(['blog.posts.view', 'blog.posts.edit']);
    $post = Post::create([
        'title' => 'Toggle target', 'slug' => 'toggle-target',
        'author_id' => $author->id, 'status' => 'draft',
    ]);

    $this->actingAs($author);

    Livewire::test(ListPosts::class)
        ->call('updateTableColumnState', 'is_featured', (string) $post->getKey(), true);

    expect($post->fresh()->is_featured)->toBeFalse();
});

it('lets a publisher feature a post via the list toggle column', function (): void {
    $publisher = blogUserWith(['blog.posts.view', 'blog.posts.edit', 'blog.posts.publish']);
    $post = Post::create([
        'title' => 'Toggle ok', 'slug' => 'toggle-ok',
        'author_id' => $publisher->id, 'status' => 'draft',
    ]);

    $this->actingAs($publisher);

    Livewire::test(ListPosts::class)
        ->call('updateTableColumnState', 'is_featured', (string) $post->getKey(), true);

    expect($post->fresh()->is_featured)->toBeTrue();
});

it('does not silently unpublish a live post when a non-publisher edits it', function (): void {
    $contributor = blogUserWith(['blog.posts.view', 'blog.posts.edit']);
    $post = Post::create([
        'title' => 'Live post', 'slug' => 'live-post',
        'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay(),
    ]);

    $this->actingAs($contributor);

    // The seeded form status is "published"; an ordinary autosave must not
    // downgrade it just because the user cannot publish.
    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Live post edited')
        ->call('autosave');

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});
