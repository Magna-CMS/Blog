<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Filament\Resources\PostResource\Pages\CreatePost;
use MagnaCms\Blog\Filament\Resources\PostResource\Pages\EditPost;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Seo\BlogSeoSource;
use MagnaCms\Blog\Seo\PostSeoMeta;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna/seo');
    $this->enablePlugin('magna-cms/blog');
    Filament::setCurrentPanel(Filament::getPanel('magna'));

    Route::get('/_test/posts', fn () => '')->name('filament.magna.resources.posts.index');
    Route::get('/_test/posts/create', fn () => '')->name('filament.magna.resources.posts.create');
    Route::get('/_test/posts/{record}/edit', fn () => '')->name('filament.magna.resources.posts.edit');
});

function seoPublisher(): User
{
    $role = Role::create(['handle' => 'seo-'.uniqid(), 'name' => 'Test role']);
    $role->grant('blog.posts.view', 'blog.posts.create', 'blog.posts.edit', 'blog.posts.publish');

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function livePost(array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'Hello World',
        'slug' => 'hello-world',
        'excerpt' => 'A short intro.',
        'status' => 'published',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
    ], $attributes));
}

it('reports SEO as active when the SEO plugin is enabled', function (): void {
    expect(PostSeoMeta::active())->toBeTrue();
});

it('registers the blog subject source with the SEO registry', function (): void {
    /** @var SeoSourceRegistry $registry */
    $registry = app(SeoSourceRegistry::class);

    expect($registry->has('blog'))->toBeTrue()
        ->and($registry->get('blog'))->toBeInstanceOf(BlogSeoSource::class);
});

it('maps a live public post to an SEO subject', function (): void {
    livePost();

    $subject = app(BlogSeoSource::class)->resolve('hello-world');

    expect($subject)->toBeInstanceOf(SeoSubject::class)
        ->and($subject->key)->toBe('blog:hello-world')
        ->and($subject->type)->toBe(SubjectType::Article)
        ->and($subject->url)->toContain('/blog/hello-world')
        ->and($subject->indexable)->toBeTrue()
        ->and($subject->title)->toBe('Hello World')
        ->and($subject->excerpt)->toBe('A short intro.');
});

it('does not resolve a draft post as an SEO subject', function (): void {
    livePost(['slug' => 'secret', 'status' => 'draft', 'published_at' => null]);

    expect(app(BlogSeoSource::class)->resolve('secret'))->toBeNull();
});

it('round-trips SEO meta through the bridge', function (): void {
    $post = livePost();

    PostSeoMeta::write($post, [
        'title' => 'Custom SEO title',
        'description' => 'Custom meta description.',
        'canonical_url' => 'https://example.com/canonical',
        'focus_keyword' => 'laravel cms',
        'robots_index' => false,
        'robots_follow' => true,
        'og_title' => 'OG title',
        'twitter_card' => 'summary_large_image',
    ]);

    $read = PostSeoMeta::read($post);

    expect($read['title'])->toBe('Custom SEO title')
        ->and($read['description'])->toBe('Custom meta description.')
        ->and($read['canonical_url'])->toBe('https://example.com/canonical')
        ->and($read['focus_keyword'])->toBe('laravel cms')
        ->and($read['robots_index'])->toBeFalse()
        ->and($read['robots_follow'])->toBeTrue()
        ->and($read['og_title'])->toBe('OG title')
        ->and($read['twitter_card'])->toBe('summary_large_image');

    // The row is attached to this exact post via the SEO plugin's own repository.
    $meta = app(SeoMetaRepository::class)->for(Post::class, (string) $post->getKey());
    expect($meta)->not->toBeNull()
        ->and($meta->title)->toBe('Custom SEO title')
        ->and($meta->focus_keywords)->toBe(['laravel cms']);
});

it('persists the SEO tab when a post is saved in the builder', function (): void {
    $this->actingAs(seoPublisher());

    Livewire::test(CreatePost::class)
        ->set('data.title', 'Builder SEO')
        ->set('data.seo_title', 'Builder SEO Title')
        ->set('data.seo_description', 'Written from the builder.')
        ->set('data.seo_robots_index', false)
        ->call('publish');

    $post = Post::query()->where('title', 'Builder SEO')->firstOrFail();

    $meta = app(SeoMetaRepository::class)->for(Post::class, (string) $post->getKey());
    expect($meta)->not->toBeNull()
        ->and($meta->title)->toBe('Builder SEO Title')
        ->and($meta->description)->toBe('Written from the builder.')
        ->and($meta->robots_index)->toBeFalse();
});

it('updates SEO meta from the edit page', function (): void {
    $user = seoPublisher();
    $post = livePost(['author_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->assertSet('data.seo_robots_index', true)
        ->set('data.seo_title', 'Edited SEO Title')
        ->set('data.seo_robots_index', false)
        ->call('publish');

    $meta = app(SeoMetaRepository::class)->for(Post::class, (string) $post->getKey());
    expect($meta?->title)->toBe('Edited SEO Title')
        ->and($meta?->robots_index)->toBeFalse()
        ->and($post->fresh()->status)->toBe(PostStatus::Published);
});
