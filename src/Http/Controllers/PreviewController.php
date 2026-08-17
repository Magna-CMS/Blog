<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use MagnaCms\Blog\Http\Resources\DynamicBlockResolver;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\BlockRenderer;

/**
 * Admin-only rendered preview of a post (any status), so an author can see how
 * the saved content looks before publishing. This is a preview aid — the real
 * frontend rendering is a separate concern.
 */
class PreviewController
{
    public function show(Request $request, Post $post, DynamicBlockResolver $resolver, BlockRenderer $renderer): View
    {
        // Ownership-aware: an author may preview their own (any status) and any
        // already-public post, but never another author's unpublished draft.
        // Editors/admins (edit-others / super admin) may preview anyone's.
        // The PostPolicy::view ability delegates to the same PostAccess rule as
        // the builder — one authorization source, idiomatic Gate entry point.
        abort_unless($request->user()?->can('view', $post) ?? false, 403);

        $document = $resolver->resolve(
            is_array($post->content) ? $post->content : ['blocks' => []],
            $post,
        );

        return view('blog::preview', [
            'post' => $post,
            'body' => $renderer->render($document),
        ]);
    }
}
