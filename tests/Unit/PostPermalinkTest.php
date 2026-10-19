<?php

declare(strict_types=1);

/**
 * The permalink pattern as a route: what the post page is mounted at, and
 * how a request's captured segments are turned back into a path for the
 * canonical check.
 */

use MagnaCms\Blog\Support\PostPermalink;

it('turns a servable pattern into a frontend-page path', function (string $pattern, string $route): void {
    expect((new PostPermalink($pattern))->routePath())->toBe($route);
})->with([
    'default' => ['/blog/{slug}', 'blog/{slug}'],
    'custom prefix' => ['/posts/{slug}', 'posts/{slug}'],
    'no prefix' => ['/{slug}', '{slug}'],
    'every token' => ['/news/{year}/{month}/{day}/{category}/{slug}', 'news/{year}/{month}/{day}/{category}/{slug}'],
    'no slashes around it' => ['blog/{slug}', 'blog/{slug}'],
]);

it('refuses a pattern the router could not serve', function (string $pattern): void {
    expect((new PostPermalink($pattern))->routePath())->toBeNull();
})->with([
    'no slug' => ['/blog/{year}'],
    'no tokens at all' => ['/blog'],
    'unknown token' => ['/blog/{author}/{slug}'],
    'token sharing a segment' => ['/blog/{year}-{slug}'],
    'repeated token' => ['/blog/{slug}/{slug}'],
    'empty segment' => ['/blog//{slug}'],
    'stray brace' => ['/blog/{slug}/x}'],
]);

it('reports the literal prefix the builder shows before the slug', function (string $pattern, string $prefix): void {
    expect((new PostPermalink($pattern))->staticPrefix())->toBe($prefix);
})->with([
    ['/blog/{slug}', '/blog/'],
    ['posts/{year}/{slug}', '/posts/'],
    ['/{slug}', '/'],
]);

it('reassembles a request path from captured segments', function (): void {
    $permalink = new PostPermalink('/news/{year}/{category}/{slug}');

    expect($permalink->pathFromParams(['year' => '2026', 'category' => 'releases', 'slug' => 'v2']))
        ->toBe('/news/2026/releases/v2');
});

it('never lets a captured segment collapse onto the canonical path', function (): void {
    // normalise() strips brace runs; a segment carrying one must not become
    // an alias of the real URL.
    $permalink = new PostPermalink('/news/{year}/{slug}');

    expect($permalink->pathFromParams(['year' => '{x}2026', 'slug' => 'v2']))->toBe('')
        ->and($permalink->pathFromParams(['year' => '2026', 'slug' => 'a/b']))->toBe('');
});
