<?php

declare(strict_types=1);

use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Models\Tag;
use MagnaCms\Blog\Support\BlogArchive;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

function seedArchiveFixture(): array
{
    $author = User::factory()->create(['email' => 'writer@example.test']);

    $parent = Category::create(['name' => 'Tech', 'slug' => 'tech', 'description' => 'All tech']);
    $child = Category::create(['name' => 'Laravel', 'slug' => 'laravel', 'parent_id' => $parent->id]);

    $tag = Tag::create(['name' => 'PHP', 'slug' => 'php']);

    $post = Post::create([
        'title' => 'Hello World',
        'slug' => 'hello-world',
        'excerpt' => 'A summary',
        'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body text']]]],
        'category_id' => $child->id,
        'author_id' => $author->id,
        'status' => 'published',
        'visibility' => 'public',
        'is_featured' => true,
        'published_at' => now()->subDay(),
    ]);
    $post->tags()->attach($tag->id);
    $post->meta()->createMany([
        ['key' => 'subtitle', 'type' => 'string', 'value' => 'The subtitle'],
        ['key' => 'rank', 'type' => 'integer', 'value' => 7],
    ]);

    return ['author' => $author];
}

function wipeBlogContent(): void
{
    // Force-delete so soft-deletes do not leave rows behind.
    Post::query()->withTrashed()->get()->each->forceDelete();
    Tag::query()->delete();
    Category::query()->delete();
}

it('round-trips content through export and import', function (): void {
    $context = seedArchiveFixture();
    $archive = app(BlogArchive::class);

    $bundle = $archive->export();
    wipeBlogContent();

    expect(Post::query()->count())->toBe(0);

    $stats = $archive->import($bundle);

    // 3 = Tech + Laravel + the seeded Uncategorised fallback category.
    expect($stats)->toBe(['categories' => 3, 'tags' => 1, 'posts' => 1]);

    $post = Post::query()->where('slug', 'hello-world')->firstOrFail();
    expect($post->title)->toBe('Hello World')
        ->and($post->excerpt)->toBe('A summary')
        ->and($post->is_featured)->toBeTrue()
        ->and($post->category->slug)->toBe('laravel')
        ->and($post->category->parent->slug)->toBe('tech')
        ->and($post->author->email)->toBe($context['author']->email)
        ->and($post->tags->pluck('slug')->all())->toBe(['php'])
        ->and($post->metaValue('subtitle'))->toBe('The subtitle')
        ->and($post->metaValue('rank'))->toBe(7)
        ->and($post->content['blocks'][0]['data']['text'])->toBe('Body text');
});

it('is idempotent — importing twice does not duplicate', function (): void {
    seedArchiveFixture();
    $archive = app(BlogArchive::class);
    $bundle = $archive->export();

    $archive->import($bundle);
    $archive->import($bundle);

    expect(Post::query()->count())->toBe(1)
        ->and(Category::query()->count())->toBe(3) // Tech + Laravel + Uncategorised
        ->and(Tag::query()->count())->toBe(1)
        ->and(Post::query()->where('slug', 'hello-world')->firstOrFail()->meta()->count())->toBe(2);
});

it('rejects a bundle with an unsupported schema', function (): void {
    expect(fn () => app(BlogArchive::class)->import(['schema' => 999, 'posts' => []]))
        ->toThrow(InvalidArgumentException::class);
});

it('drops meta keys that a re-import no longer contains', function (): void {
    seedArchiveFixture();
    $archive = app(BlogArchive::class);
    $bundle = $archive->export();

    // Remove one meta key from the bundle, then re-import.
    $bundle['posts'][0]['meta'] = [['key' => 'subtitle', 'type' => 'string', 'value' => 'The subtitle']];
    $archive->import($bundle);

    $post = Post::query()->where('slug', 'hello-world')->firstOrFail();
    expect($post->meta()->count())->toBe(1)
        ->and($post->metaValue('rank'))->toBeNull();
});

it('exports to a file and imports it back through the artisan commands', function (): void {
    seedArchiveFixture();
    $path = sys_get_temp_dir().'/blog-archive-'.uniqid().'.json';

    $this->artisan('blog:export', ['--out' => $path])->assertSuccessful();
    expect(is_file($path))->toBeTrue();

    wipeBlogContent();
    expect(Post::query()->count())->toBe(0);

    $this->artisan('blog:import', ['file' => $path])->assertSuccessful();
    expect(Post::query()->where('slug', 'hello-world')->exists())->toBeTrue();

    @unlink($path);
});

it('fails the import command on a missing file', function (): void {
    $this->artisan('blog:import', ['file' => sys_get_temp_dir().'/does-not-exist.json'])
        ->assertFailed();
});

it('sanitises imported content and never exports the password hash', function (): void {
    Post::create([
        'title' => 'Guarded', 'slug' => 'guarded', 'visibility' => 'password', 'password' => 'secret',
        'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'ok <script>alert(1)</script>']]]],
    ]);

    $bundle = app(BlogArchive::class)->export();
    $exported = collect($bundle['posts'])->firstWhere('slug', 'guarded');

    expect($exported)->not->toHaveKey('password');

    wipeBlogContent();
    app(BlogArchive::class)->import($bundle);

    $body = Post::query()->where('slug', 'guarded')->firstOrFail()->content['blocks'][0]['data']['text'];
    expect($body)->not->toContain('<script>');
});
