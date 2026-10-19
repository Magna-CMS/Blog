<?php

declare(strict_types=1);

/**
 * The dynamic blocks a post page renders. The delivery API reads the same
 * resolver, so its keys only ever grow: what BlockRenderer needs is added
 * beside what the API has always returned.
 */

use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Http\Resources\DynamicBlockResolver;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\BlockRenderer;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

function resolverPost(array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'A post',
        'slug' => 'a-post-'.bin2hex(random_bytes(4)),
        'locale' => 'en',
        'status' => PostStatus::Published,
        'visibility' => PostVisibility::Public,
        'published_at' => now()->subDay(),
    ], $attributes));
}

it('gives related posts the url and image the renderer reads, keeping the api keys', function (): void {
    $settings = BlogSettings::get();
    $settings->permalink_pattern = '/news/{year}/{category}/{slug}';
    $settings->save();

    $category = Category::create(['name' => 'Releases', 'slug' => 'releases']);
    $post = resolverPost(['category_id' => $category->id]);
    resolverPost([
        'title' => 'Sibling', 'slug' => 'sibling', 'category_id' => $category->id,
        'featured_image' => 'blog/sibling.jpg', 'published_at' => now()->setDate(2026, 2, 3),
    ]);

    $document = app(DynamicBlockResolver::class)->resolve(
        ['blocks' => [['type' => 'relatedPosts', 'data' => ['count' => 3, 'by' => 'category']]]],
        $post,
    );

    $related = $document['blocks'][0]['data']['posts'][0];

    expect($related)->toHaveKeys(['title', 'slug', 'excerpt', 'featured_image', 'url', 'image'])
        ->and($related['slug'])->toBe('sibling')
        ->and($related['url'])->toBe('/news/2026/releases/sibling')
        ->and($related['image'])->toBe($related['featured_image'])
        ->and($related['image'])->toContain('blog/sibling.jpg');

    // And the renderer now links it — before, it read keys nobody set.
    $html = app(BlockRenderer::class)->render($document);
    expect($html)->toContain('href="/news/2026/releases/sibling"')
        ->and($html)->toContain('blog/sibling.jpg');
});

it('keeps the stored toc title and numbering beside the resolved items', function (): void {
    $post = resolverPost();

    $document = app(DynamicBlockResolver::class)->resolve(['blocks' => [
        ['type' => 'header', 'data' => ['text' => 'Intro', 'level' => 2]],
        ['type' => 'toc', 'data' => ['depth' => 3, 'title' => 'On this page', 'ordered' => true]],
    ]], $post);

    $toc = $document['blocks'][1]['data'];

    expect($toc['title'])->toBe('On this page')
        ->and($toc['ordered'])->toBeTrue()
        ->and($toc['items'][0]['anchor'])->toBe('intro');

    $html = app(BlockRenderer::class)->render($document);
    expect($html)->toContain('<strong>On this page</strong><ol>')
        ->and($html)->toContain('<a href="#intro">Intro</a>');
});
