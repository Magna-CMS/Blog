<?php

declare(strict_types=1);

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Magna\Auth\Role;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use MagnaCms\Blog\Http\Controllers\PreviewController;
use MagnaCms\Blog\Http\Resources\DynamicBlockResolver;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\BlockRenderer;
use MagnaCms\Blog\Support\PostAccess;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Invoke PreviewController::show as $user would, returning the View or throwing. */
function previewAs(User $user, Post $post): View
{
    $request = Request::create('/blog/editor/preview/'.$post->id);
    $request->setUserResolver(fn (): User => $user);

    return (new PreviewController)->show(
        $request,
        $post,
        app(DynamicBlockResolver::class),
        app(BlockRenderer::class),
    );
}

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

/** User whose single role grants exactly $permissions (optionally super admin). */
function previewUser(array $permissions = [], bool $super = false): User
{
    $role = Role::create(['handle' => 'prev-'.uniqid(), 'name' => 'Role', 'is_super_admin' => $super]);
    if ($permissions !== []) {
        $role->grant(...$permissions);
    }

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function previewPost(User $author, array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'Post '.uniqid(),
        'slug' => 'post-'.uniqid(),
        'author_id' => $author->id,
        'status' => 'draft',
        'visibility' => 'public',
    ], $attributes));
}

// ---- PostAccess::canView matrix (the single authorization authority) --------

it('lets an author preview their own unpublished post but not another author drafts', function (): void {
    $authorA = previewUser(['blog.posts.view']);
    $authorB = previewUser(['blog.posts.view']);

    $draftA = previewPost($authorA);
    $draftB = previewPost($authorB);

    expect(PostAccess::canView($authorA, $draftA))->toBeTrue()
        ->and(PostAccess::canView($authorA, $draftB))->toBeFalse()
        ->and(PostAccess::canView($authorB, $draftB))->toBeTrue();
});

it('lets an editor and an administrator preview any author unpublished post', function (): void {
    $author = previewUser(['blog.posts.view']);
    $editor = previewUser(['blog.posts.view', 'blog.posts.edit-others']);
    $admin = previewUser(super: true);

    $draft = previewPost($author);

    expect(PostAccess::canView($editor, $draft))->toBeTrue()
        ->and(PostAccess::canView($admin, $draft))->toBeTrue();
});

it('lets any viewer preview an already-public published post', function (): void {
    $authorA = previewUser(['blog.posts.view']);
    $authorB = previewUser(['blog.posts.view']);

    $published = previewPost($authorB, [
        'status' => 'published',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
    ]);

    // A non-owner author can view B's post because it is already public.
    expect(PostAccess::canView($authorA, $published))->toBeTrue();
});

it('never lets a non-owner author preview a scheduled or private post of another author', function (): void {
    $authorA = previewUser(['blog.posts.view']);
    $authorB = previewUser(['blog.posts.view']);

    $scheduled = previewPost($authorB, ['status' => 'scheduled', 'published_at' => now()->addDay()]);
    $futureDated = previewPost($authorB, ['status' => 'published', 'published_at' => now()->addDay()]);
    $private = previewPost($authorB, ['status' => 'published', 'visibility' => 'private', 'published_at' => now()->subDay()]);
    $password = previewPost($authorB, ['status' => 'published', 'visibility' => 'password', 'published_at' => now()->subDay(), 'password' => 'x']);

    expect(PostAccess::canView($authorA, $scheduled))->toBeFalse()
        ->and(PostAccess::canView($authorA, $futureDated))->toBeFalse()
        ->and(PostAccess::canView($authorA, $private))->toBeFalse()
        ->and(PostAccess::canView($authorA, $password))->toBeFalse();
});

it('denies preview to a user without the view permission and to guests', function (): void {
    $noView = previewUser(['blog.posts.edit']); // can edit but lacks view
    $author = previewUser(['blog.posts.view']);
    $draft = previewPost($author);

    expect(PostAccess::canView($noView, $draft))->toBeFalse()
        ->and(PostAccess::canView(null, $draft))->toBeFalse();
});

// ---- Controller wiring: it enforces canView, not the old view||edit gate ----

it('aborts 403 when an author previews another author draft through the controller', function (): void {
    $authorA = previewUser(['blog.posts.view']);
    $draftB = previewPost(previewUser(['blog.posts.view']));

    expect(fn (): View => previewAs($authorA, $draftB))
        ->toThrow(HttpException::class);
});

it('renders the preview when an author previews their own draft through the controller', function (): void {
    $author = previewUser(['blog.posts.view']);
    $draft = previewPost($author, ['content' => ['blocks' => []]]);

    expect(previewAs($author, $draft))->toBeInstanceOf(View::class);
});
