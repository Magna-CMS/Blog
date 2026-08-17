<?php

declare(strict_types=1);

use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\BlogArchive;
use MagnaCms\Blog\Support\WxrImporter;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

function sampleWxr(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <wp:author>
        <wp:author_login>jane</wp:author_login>
        <wp:author_email>jane@example.test</wp:author_email>
    </wp:author>
    <wp:category>
        <wp:category_nicename>news</wp:category_nicename>
        <wp:category_parent></wp:category_parent>
        <wp:cat_name>News</wp:cat_name>
    </wp:category>
    <wp:tag>
        <wp:tag_slug>php</wp:tag_slug>
        <wp:tag_name>PHP</wp:tag_name>
    </wp:tag>
    <item>
        <title>Hello WXR</title>
        <dc:creator>jane</dc:creator>
        <content:encoded><![CDATA[<h2>Intro</h2><p>Body <strong>text</strong></p><ul><li>one</li><li>two</li></ul>]]></content:encoded>
        <wp:post_id>101</wp:post_id>
        <wp:post_name>hello-wxr</wp:post_name>
        <wp:post_date>2025-02-01 10:00:00</wp:post_date>
        <wp:post_date_gmt>2025-02-01 10:00:00</wp:post_date_gmt>
        <wp:status>publish</wp:status>
        <wp:post_type>post</wp:post_type>
        <wp:comment_status>open</wp:comment_status>
        <category domain="category" nicename="news">News</category>
        <category domain="post_tag" nicename="php">PHP</category>
    </item>
    <item>
        <title>A Page</title>
        <wp:post_name>a-page</wp:post_name>
        <wp:post_type>page</wp:post_type>
        <wp:status>publish</wp:status>
    </item>
    <item>
        <title>Trashed</title>
        <wp:post_name>trashed</wp:post_name>
        <wp:post_type>post</wp:post_type>
        <wp:status>trash</wp:status>
    </item>
</channel>
</rss>
XML;
}

it('maps a WXR export into a bundle and imports it', function (): void {
    User::factory()->create(['email' => 'jane@example.test']);

    $bundle = app(WxrImporter::class)->toBundle(sampleWxr());
    $stats = app(BlogArchive::class)->import($bundle);

    // Only the single real post is imported (page + trash skipped).
    expect($stats['posts'])->toBe(1)
        ->and($stats['categories'])->toBe(1)
        ->and($stats['tags'])->toBe(1);

    $post = Post::query()->where('slug', 'hello-wxr')->firstOrFail();

    expect($post->title)->toBe('Hello WXR')
        ->and($post->status)->toBe(PostStatus::Published)
        ->and($post->visibility)->toBe(PostVisibility::Public)
        ->and($post->author->email)->toBe('jane@example.test')
        ->and($post->category->slug)->toBe('news')
        ->and($post->tags->pluck('slug')->all())->toBe(['php']);

    $types = collect($post->content['blocks'])->pluck('type')->all();
    expect($types)->toBe(['header', 'paragraph', 'list']);
});

it('skips pages and trashed items', function (): void {
    $bundle = app(WxrImporter::class)->toBundle(sampleWxr());

    $slugs = collect($bundle['posts'])->pluck('slug')->all();
    expect($slugs)->toBe(['hello-wxr']);
});

it('rejects a non-WXR file', function (): void {
    expect(fn () => app(WxrImporter::class)->toBundle('<html><body>not wxr</body></html>'))
        ->toThrow(InvalidArgumentException::class);
});

it('imports a WXR file through the artisan command', function (): void {
    $path = sys_get_temp_dir().'/wxr-'.uniqid().'.xml';
    file_put_contents($path, sampleWxr());

    $this->artisan('blog:import-wxr', ['file' => $path])->assertSuccessful();

    expect(Post::query()->where('slug', 'hello-wxr')->exists())->toBeTrue();

    @unlink($path);
});
