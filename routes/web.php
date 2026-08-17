<?php

use Illuminate\Support\Facades\Route;
use MagnaCms\Blog\Http\Controllers\EditorToolController;
use MagnaCms\Blog\Http\Controllers\PreviewController;

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
