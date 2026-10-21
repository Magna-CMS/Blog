<?php

declare(strict_types=1);

/*
 * The builder's Publish date field showed empty for every published post.
 * The canvas fill seeds `published_at` with the record's serialised Carbon
 * ("2026-10-20T09:04:00.000000Z"), which a datetime-local input cannot
 * parse — so the date could be neither seen nor changed, and it was the UTC
 * instant rather than the site's own clock.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Filament\Resources\PostResource\Pages\EditPost;
use MagnaCms\Blog\Models\Post;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
    Filament::setCurrentPanel(Filament::getPanel('magna'));

    Route::get('/_test/posts', fn () => '')->name('filament.magna.resources.posts.index');
    Route::get('/_test/posts/create', fn () => '')->name('filament.magna.resources.posts.create');
    Route::get('/_test/posts/{record}/edit', fn () => '')->name('filament.magna.resources.posts.edit');

    config(['app.timezone' => 'Asia/Kolkata']);
    date_default_timezone_set('Asia/Kolkata');

    $role = Role::create(['handle' => 'pd-'.uniqid(), 'name' => 'Admin', 'is_super_admin' => true]);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);
});

afterEach(function (): void {
    date_default_timezone_set('UTC');
});

/** A published post dated $local on the site's clock (app.timezone). */
function publishedAt(string $local): Post
{
    return Post::create([
        'title' => 'Dated', 'slug' => 'dated-'.uniqid(), 'status' => 'published', 'visibility' => 'public',
        'content' => ['blocks' => []], 'published_at' => Carbon::parse($local, 'Asia/Kolkata'),
    ]);
}

it('shows a published post\'s date in the field, on the site\'s clock', function (): void {
    $post = publishedAt('2026-10-20 14:34:00');

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->assertSet('data.published_at', '2026-10-20 14:34:00');
});

it('keeps the publish date when a post is saved without touching it', function (): void {
    $post = publishedAt('2026-10-20 14:34:00');
    $before = $post->fresh()->published_at?->getTimestamp();

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Dated, edited')
        ->call('autosave');

    expect($post->fresh()->published_at?->getTimestamp())->toBe($before)
        ->and($post->fresh()->title)->toBe('Dated, edited');
});

it('saves a date picked in the field as the site\'s local time', function (): void {
    $post = publishedAt('2026-10-20 14:34:00');

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.published_at', '2026-10-18 08:00:00')
        ->call('autosave');

    expect($post->fresh()->published_at?->copy()->utc()->format('Y-m-d H:i'))->toBe('2026-10-18 02:30');
});
