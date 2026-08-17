<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Http\Controllers\TaxonomyController;
use MagnaCms\Blog\Models\Category;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

/** A → B → C chain, returned as [A, B, C]. */
function categoryChain(): array
{
    $a = Category::create(['name' => 'A', 'slug' => 'cat-a']);
    $b = Category::create(['name' => 'B', 'slug' => 'cat-b', 'parent_id' => $a->id]);
    $c = Category::create(['name' => 'C', 'slug' => 'cat-c', 'parent_id' => $b->id]);

    return [$a, $b, $c];
}

it('detects the whole descendant subtree', function (): void {
    [$a, $b, $c] = categoryChain();

    expect($a->descendantIds())->toEqualCanonicalizing([$b->id, $c->id])
        ->and($b->descendantIds())->toEqualCanonicalizing([$c->id])
        ->and($c->descendantIds())->toBe([]);
});

it('refuses to reparent a category under itself (forged request)', function (): void {
    [$a] = categoryChain();

    // Simulate a forged Livewire/import payload setting parent_id to self.
    $a->update(['parent_id' => $a->id]);

    expect($a->fresh()->parent_id)->toBeNull();
});

it('refuses to reparent a category under one of its own descendants', function (): void {
    [$a, , $c] = categoryChain();

    // A cannot become a child of C (which is A's grandchild) — that is a cycle.
    $a->update(['parent_id' => $c->id]);

    expect($a->fresh()->parent_id)->toBeNull();
});

it('still allows a legitimate reparent to an unrelated category', function (): void {
    [$a, $b] = categoryChain();
    $other = Category::create(['name' => 'Other', 'slug' => 'cat-other']);

    // Moving B under an unrelated top-level category is fine.
    $b->update(['parent_id' => $other->id]);

    expect($b->fresh()->parent_id)->toBe($other->id);
});

it('builds a nested delivery tree for a valid hierarchy', function (): void {
    categoryChain();

    $response = (new TaxonomyController)->categories(Request::create('/api/v1/blog/categories'));
    $data = $response->getData(true)['data'];

    // A sits at the root (alongside the seeded "Uncategorised"); B nests under A,
    // C under B.
    $a = collect($data)->firstWhere('slug', 'cat-a');

    expect($a)->not->toBeNull()
        ->and($a['children'][0]['slug'])->toBe('cat-b')
        ->and($a['children'][0]['children'][0]['slug'])->toBe('cat-c');
});

it('terminates tree traversal even if the data contains a cycle (defence in depth)', function (): void {
    // Craft a byParent map whose root node is its own child, which without the
    // visited-set guard would recurse forever.
    $node = (new Category(['name' => 'Loop', 'slug' => 'loop']))->forceFill(['id' => 1]);
    $byParent = [0 => [$node], 1 => [$node]];

    $method = new ReflectionMethod(TaxonomyController::class, 'tree');
    $method->setAccessible(true);

    $tree = $method->invoke(new TaxonomyController, $byParent, 0, []);

    // It returns (does not hang) and does not descend into the self-loop.
    expect($tree)->toHaveCount(1)
        ->and($tree[0]['children'])->toBe([]);
});
