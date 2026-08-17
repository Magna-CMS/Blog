<?php

declare(strict_types=1);

use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\DefaultCategory;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

it('files a post with no category under Uncategorised', function (): void {
    $post = Post::create(['title' => 'Loose', 'slug' => 'loose', 'content' => ['blocks' => []]]);

    expect($post->fresh()->category->slug)->toBe(DefaultCategory::SLUG);
});

it('honours the configured default category over Uncategorised', function (): void {
    $news = Category::create(['name' => 'News', 'slug' => 'news']);
    $settings = BlogSettings::get();
    $settings->default_category_id = $news->id;
    $settings->save();

    $post = Post::create(['title' => 'Loose 2', 'slug' => 'loose-2', 'content' => ['blocks' => []]]);

    expect($post->fresh()->category->slug)->toBe('news');
});

it('reassigns posts to Uncategorised when their category is deleted', function (): void {
    $tech = Category::create(['name' => 'Tech', 'slug' => 'tech']);
    $post = Post::create(['title' => 'T', 'slug' => 't', 'content' => ['blocks' => []], 'category_id' => $tech->id]);

    $tech->delete();

    expect($post->fresh()->category->slug)->toBe(DefaultCategory::SLUG);
});

it('never deletes the Uncategorised category', function (): void {
    $uncategorised = Category::query()->where('slug', DefaultCategory::SLUG)->firstOrFail();

    expect($uncategorised->delete())->toBeFalse()
        ->and(Category::query()->where('slug', DefaultCategory::SLUG)->exists())->toBeTrue();
});
