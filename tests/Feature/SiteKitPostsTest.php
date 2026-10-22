<?php

declare(strict_types=1);

/**
 * Posts in a site kit, under 'blog:posts' — how a theme's demo content ships
 * real posts. A kit is a file somebody can edit, so the hostile rows matter:
 * content is sanitized, inline images must really be the image they claim,
 * and a remote URL is never fetched.
 */

use Illuminate\Support\Facades\Storage;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\SiteKit\PostsSection;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
    Storage::fake('public');
});

function tinyJpeg(): string
{
    $image = imagecreatetruecolor(4, 3);
    ob_start();
    imagejpeg($image);

    return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
}

function demoPostRow(array $overrides = []): array
{
    return array_merge([
        'slug' => 'slow-software',
        'title' => 'The Art of Slow Software',
        'excerpt' => 'A manifesto for permanence.',
        'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Stillness <script>alert(1)</script>matters.']]]],
        'category' => ['slug' => 'craft', 'name' => 'Craft'],
        'tags' => ['design', ['slug' => 'typography', 'name' => 'Typography']],
        'featured_image' => tinyJpeg(),
        'is_featured' => true,
        'published_days_ago' => 3,
    ], $overrides);
}

it('is registered under its namespaced key', function (): void {
    $sections = app('magna.site_kit.sections');

    expect(collect($sections)->map->key()->all())->toContain('blog:posts');
});

it('creates a published post with its category, tags and featured image', function (): void {
    $author = User::factory()->create();

    $written = app(PostsSection::class)->apply([demoPostRow()], (string) $author->id);

    $post = Post::query()->firstWhere('slug', 'slow-software');

    expect($written)->toBe(1)
        ->and($post->status)->toBe(PostStatus::Published)
        ->and($post->is_featured)->toBeTrue()
        ->and($post->author_id)->toBe($author->id)
        ->and($post->category->name)->toBe('Craft')
        ->and($post->tags->pluck('slug')->sort()->values()->all())->toBe(['design', 'typography'])
        ->and($post->published_at->isPast())->toBeTrue()
        ->and(now()->diffInDays($post->published_at, true))->toBeGreaterThanOrEqual(2.9)
        ->and($post->featured_image)->toStartWith('blog-featured/slow-software-');

    Storage::disk('public')->assertExists($post->featured_image);

    // Sanitized like the editor sanitizes.
    expect(json_encode($post->content))->not->toContain('<script>');
});

it('drops a featured image that is not what it claims, too large, or remote', function (string $image): void {
    app(PostsSection::class)->apply([demoPostRow(['featured_image' => $image])]);

    expect(Post::query()->firstWhere('slug', 'slow-software')->featured_image)->toBeNull();
})->with([
    'text posing as a jpeg' => ['data:image/jpeg;base64,'.base64_encode('<?php echo 1;')],
    'svg' => ['data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>')],
    'remote url' => ['https://evil.example/x.jpg'],
    'path traversal' => ['../../.env'],
]);

it('never renames a category the site already has', function (): void {
    Category::query()->create(['slug' => 'craft', 'name' => 'Our Craft']);

    app(PostsSection::class)->apply([demoPostRow()]);

    expect(Category::query()->firstWhere('slug', 'craft')->name)->toBe('Our Craft');
});

it('reports added, changed and local-only posts by slug, counting trashed posts as present', function (): void {
    app(PostsSection::class)->apply([demoPostRow(), demoPostRow(['slug' => 'binned', 'title' => 'Binned'])]);
    Post::query()->firstWhere('slug', 'binned')->delete();

    $diff = app(PostsSection::class)->diff([
        demoPostRow(['title' => 'Retitled']),
        demoPostRow(['slug' => 'binned', 'title' => 'Binned']),
        demoPostRow(['slug' => 'brand-new']),
    ]);

    expect($diff['added'])->toBe(['brand-new'])
        ->and($diff['changed'])->toBe(['slow-software'])
        ->and($diff['localOnly'])->toBe([]);
});

it('skips a row with no slug or title, and round-trips through export', function (): void {
    $section = app(PostsSection::class);

    expect($section->apply([['slug' => '', 'title' => 'x'], ['slug' => 'no-title']]))->toBe(0);

    $section->apply([demoPostRow()]);
    $exported = $section->export();

    expect($exported)->toHaveCount(1)
        ->and($exported[0]['slug'])->toBe('slow-software')
        ->and($exported[0]['category'])->toBe(['slug' => 'craft', 'name' => 'Craft'])
        ->and($exported[0]['featured_image'])->toStartWith('blog-featured/');
});
