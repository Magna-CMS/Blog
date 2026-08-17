<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Filament\Resources\PostResource\Pages\CreatePost;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Seo\PostSeoMeta;

uses(PluginTestCase::class);

// The SEO plugin is deliberately NOT enabled here, so the blog must behave as it
// does on a site without it: the SEO tab shows a prompt, and every SEO code path
// is an inert no-op that never touches the (absent) SEO plugin.
beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
    Filament::setCurrentPanel(Filament::getPanel('magna'));

    Route::get('/_test/posts', fn () => '')->name('filament.magna.resources.posts.index');
    Route::get('/_test/posts/create', fn () => '')->name('filament.magna.resources.posts.create');
    Route::get('/_test/posts/{record}/edit', fn () => '')->name('filament.magna.resources.posts.edit');
});

it('reports SEO as inactive when the plugin is absent', function (): void {
    expect(PostSeoMeta::active())->toBeFalse();
});

it('returns blank, indexable SEO defaults when inactive', function (): void {
    $post = Post::create([
        'title' => 'No SEO', 'slug' => 'no-seo',
        'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay(),
    ]);

    $read = PostSeoMeta::read($post);

    expect($read['title'])->toBeNull()
        ->and($read['robots_index'])->toBeTrue()
        ->and($read['robots_follow'])->toBeTrue();
});

it('silently ignores a SEO write when the plugin is absent', function (): void {
    $post = Post::create([
        'title' => 'No SEO write', 'slug' => 'no-seo-write',
        'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay(),
    ]);

    // Must not throw even though no SEO storage exists.
    PostSeoMeta::write($post, ['title' => 'Ignored']);

    expect(PostSeoMeta::active())->toBeFalse();
});

it('shows the install prompt in the SEO tab when the plugin is absent', function (): void {
    $role = Role::create(['handle' => 'noseo-'.uniqid(), 'name' => 'Test role']);
    $role->grant('blog.posts.view', 'blog.posts.create', 'blog.posts.edit');
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);

    Livewire::test(CreatePost::class)->assertSee('SEO tools not available');
});
