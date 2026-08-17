<?php

use Illuminate\Support\Facades\Route;
use MagnaCms\Blog\Http\Controllers\CommentController;
use MagnaCms\Blog\Http\Controllers\DeliveryController;
use MagnaCms\Blog\Http\Controllers\ReactionController;
use MagnaCms\Blog\Http\Controllers\TaxonomyController;

// Delivery routes are auto-prefixed at /api/v1/blog/ by the PluginManager and
// guarded by `magna.api`, which requires a valid delivery-scope bearer token
// (with per-token rate limiting). The read routes only ever return published,
// public posts, so a single delivery token is safe to embed in a headless
// frontend or static-site build.

Route::middleware(['magna.api'])
    ->get('/posts', [DeliveryController::class, 'index'])
    ->name('blog.posts.index');

Route::middleware(['magna.api'])
    ->get('/posts/{slug}', [DeliveryController::class, 'show'])
    ->name('blog.posts.show');

Route::middleware(['magna.api'])
    ->get('/categories', [TaxonomyController::class, 'categories'])
    ->name('blog.categories.index');

Route::middleware(['magna.api'])
    ->get('/tags', [TaxonomyController::class, 'tags'])
    ->name('blog.tags.index');

Route::middleware(['magna.api'])
    ->get('/posts/{slug}/comments', [CommentController::class, 'index'])
    ->name('blog.comments.index');

// Submissions are rate-limited to blunt spam floods.
Route::middleware(['magna.api', 'throttle:20,1'])
    ->post('/posts/{slug}/comments', [CommentController::class, 'store'])
    ->name('blog.comments.store');

// Anonymous reaction toggle, rate-limited to blunt abuse.
Route::middleware(['magna.api', 'throttle:60,1'])
    ->post('/posts/{slug}/reactions', [ReactionController::class, 'store'])
    ->name('blog.reactions.store');
