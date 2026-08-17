<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Resources\PostResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Filament\Concerns\NormalisesFeaturedImage;
use MagnaCms\Blog\Filament\Resources\PostResource;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\PostAccess;
use MagnaCms\Blog\Support\PostWorkflow;
use MagnaCms\Blog\Support\SlugGenerator;

/**
 * The full-screen post builder in "create" mode. Unlike a normal Filament create
 * page, saving never navigates: the first save creates the draft in place and the
 * address bar is updated to the edit URL via history.replaceState; every later
 * save updates the same record. This keeps the editor mounted and the caret where
 * it is, so typing a title is never interrupted by a reload (WordPress-style).
 *
 * @property Post $record
 * @property Schema $sidebarForm
 * @property Schema $seoForm
 */
class CreatePost extends CreateRecord
{
    use EditsPostInBuilder;
    use NormalisesFeaturedImage;

    protected static string $resource = PostResource::class;

    protected string $view = 'blog::filament.post-editor';

    public bool $isEditMode = false;

    /** Status forced by the Save Draft / Publish buttons; null for autosave. */
    public ?string $forcedStatus = null;

    /** Id of the draft once persisted this session; drives create-vs-update. */
    public int|string|null $draftId = null;

    /** True while an autosave is persisting, so no revision/webhook is recorded. */
    protected bool $autosaving = false;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function mount(): void
    {
        parent::mount();
        $this->seedSidebarState();
    }

    public function saveDraft(): void
    {
        if (! $this->requireTitle()) {
            return;
        }

        $this->forcedStatus = PostStatus::Draft->value;
        $this->persist();
        $this->editorStatus = 'draft_saved';
    }

    public function publish(): void
    {
        abort_unless($this->userCanPublish(), 403);

        if (! $this->requireTitle()) {
            return;
        }

        $this->forcedStatus = PostStatus::Published->value;
        $this->persist();
        $this->editorStatus = 'published';
        $this->dispatch('blog-published', message: (string) __('blog::builder.notifications.published'));
    }

    /** Contributor action: submit the post for review. */
    public function submitForReview(): void
    {
        if (! $this->requireTitle()) {
            return;
        }

        $this->forcedStatus = PostStatus::PendingReview->value;
        $this->persist();
        $this->editorStatus = 'pending';
        Notification::make()->title(__('blog::builder.notifications.submitted_for_review'))->success()->send();
    }

    /**
     * Silent background save. Needs a title (the only required field) before it
     * will create the initial draft; thereafter it updates the same record.
     */
    public function autosave(): void
    {
        if (blank($this->data['title'] ?? null)) {
            return;
        }

        $this->forcedStatus = null;
        $this->autosaving = true;

        try {
            $this->persist();
        } finally {
            $this->autosaving = false;
        }

        $this->editorStatus = 'draft_saved';
        $this->dispatch('blog-autosaved');
    }

    /**
     * Create the draft on the first save and update it on every later one, without
     * ever redirecting. On first create the URL is rewritten to the edit page so a
     * refresh or bookmark resolves correctly, but the Livewire component (and the
     * mounted editor) is never torn down.
     */
    protected function persist(): void
    {
        $existing = $this->draftId !== null ? Post::find($this->draftId) : null;

        // draftId is a public Livewire property, so a crafted request can point it
        // at any post id. Re-assert authority over an existing record server-side —
        // exactly as EditPost does — so the create page can never be turned into a
        // vector for overwriting or hijacking (author_id is reassigned below)
        // another author's post. A user only ever legitimately reaches here with
        // the draft they just created (which they own).
        if ($existing !== null) {
            abort_unless(
                PostAccess::owns(auth()->user(), $existing) || PostAccess::canManageOthers(auth()->user()),
                403,
            );
        }

        $data = $this->mutatePostData(
            array_merge($this->data ?? [], $this->sidebarForm->getState()),
            $existing?->status->value,
            $existing?->getKey(),
        );

        if ($existing === null) {
            $post = $this->createPostResilientToSlugRace($data);
            $this->draftId = $post->getKey();
            $this->record = $post;
            $this->isEditMode = true;

            $url = PostResource::getUrl('edit', ['record' => $post->getKey()]);
            $this->js('window.history.replaceState({}, "", '.json_encode($url, JSON_UNESCAPED_SLASHES).')');
        } else {
            $existing->autosaving = $this->autosaving;
            $existing->update($data);
            $this->record = $existing;
        }

        $this->record->tags()->sync($this->tagIds());
        $this->syncPostMeta();
        $this->syncCoAuthors();
        $this->syncSeoMeta();

        // Keep the form in sync with what was persisted so the next autosave does
        // not revert the status or re-collide the slug.
        $this->data['status'] = $data['status'];
        $this->data['slug'] = $data['slug'];
    }

    /**
     * Shared create/update transformation: authorisation-clamped status, slug,
     * forced authorship, sanitised content and normalised featured image.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutatePostData(array $data, ?string $current, int|string|null $ignoreId): array
    {
        $data['status'] = PostWorkflow::resolveStatus(
            $this->forcedStatus,
            $data['status'] ?? null,
            $this->userCanPublish(),
            $current,
        );

        // Featuring is a publish-tier act; a user without publish permission can
        // never set it on a new post.
        $data['is_featured'] = $this->userCanPublish() && ($data['is_featured'] ?? false);

        if (($data['visibility'] ?? null) === PostVisibility::Password->value && blank($data['password'] ?? null)) {
            throw ValidationException::withMessages([
                'data.password' => 'A password is required for password-protected posts.',
            ]);
        }

        // No author field in the form, so any incoming author_id is a crafted
        // (impersonation) value. On create, stamp the current user as author. On
        // an update — reachable via a forged draftId pointing at an existing post,
        // where only an edit-others holder passes persist()'s ownership gate —
        // never touch author_id: preserve the real author and drop any injected
        // value so the create page can neither steal a byline nor impersonate.
        if ($current === null) {
            $data['author_id'] = auth()->id();
        } else {
            unset($data['author_id']);
        }

        return $this->normaliseFeaturedImageForSave($this->normalisePostForm($data, $ignoreId));
    }

    /**
     * Create the post, retrying with a freshly de-collided slug if a concurrent
     * request grabbed the same slug between generation and insert. The DB unique
     * constraint stays the final authority; this only turns the rare race into a
     * graceful retry instead of a 500. Bounded to a few attempts to avoid looping;
     * any non-slug unique violation is re-thrown untouched.
     *
     * @param  array<string, mixed>  $data
     */
    private function createPostResilientToSlugRace(array $data): Post
    {
        $slugs = app(SlugGenerator::class);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return Post::create($data);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === 3 || ! str_contains(Str::lower($e->getMessage()), 'slug')) {
                    throw $e;
                }

                $data['slug'] = $slugs->generate(
                    ((string) $data['slug']).'-'.Str::lower(Str::random(4)),
                    'blog_posts',
                );
            }
        }

        // Unreachable: the loop either returns or throws on the final attempt.
        return Post::create($data);
    }

    /** Warn and stop when there is no title yet (autosave stays silent instead). */
    private function requireTitle(): bool
    {
        if (blank($this->data['title'] ?? null)) {
            Notification::make()->title(__('blog::builder.notifications.title_required'))->warning()->send();

            return false;
        }

        return true;
    }

    /**
     * @return list<int>
     */
    private function tagIds(): array
    {
        $tags = $this->data['tags'] ?? [];

        return is_array($tags) ? array_values(array_map('intval', $tags)) : [];
    }
}
