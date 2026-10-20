<?php

use Illuminate\Support\Facades\Route;
use MagnaCms\Blog\Http\Controllers\EditorToolController;
use MagnaCms\Blog\Http\Controllers\PreviewController;
use MagnaCms\Blog\Http\Controllers\PublicCommentController;

// Admin-only Editor.js support endpoints. Mounted at the application root with
// the `web` middleware group (session auth + CSRF); namespaced under /blog/editor
// to avoid colliding with other routes. Authorization is enforced per action in
// the controller (post create/edit permission).

Route::middleware(['web', 'auth'])
    ->prefix('blog/editor')
    ->name('blog.editor.')
    ->group(function (): void {
        Route::get('link-preview', [EditorToolController::class, 'linkPreview'])->name('link-preview');
        Route::get('rss', [EditorToolController::class, 'rss'])->name('rss');
        Route::post('attaches', [EditorToolController::class, 'attaches'])->name('attaches');
        Route::get('preview/{post}', [PreviewController::class, 'show'])->name('preview');
    });

// The post page's public comment form. Under /_blog, a prefix no permalink
// pattern can produce, so a POST can never land on (and 405 against) a post
// URL. Session + CSRF from the `web` group: the form's token comes from
// core's /_magna/form-token, because the page itself may be served from
// the page cache. Rate limited per visitor.
Route::middleware('web')
    ->prefix(PublicCommentController::PREFIX)
    ->name('blog.public-comments.')
    ->group(function (): void {
        Route::post('{post}', [PublicCommentController::class, 'store'])
            ->whereNumber('post')
            ->middleware('throttle:10,1')
            ->name('store');
        Route::get('{post}/sign-in', [PublicCommentController::class, 'signIn'])
            ->whereNumber('post')
            ->name('sign-in');
    });
