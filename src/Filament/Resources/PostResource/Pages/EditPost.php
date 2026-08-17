<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Resources\PostResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Filament\Concerns\NormalisesFeaturedImage;
use MagnaCms\Blog\Filament\Resources\PostResource;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\PostAccess;
use MagnaCms\Blog\Support\PostWorkflow;

/**
 * @property Post $record
 * @property Schema $sidebarForm
 * @property Schema $seoForm
 */
class EditPost extends EditRecord
{
    use EditsPostInBuilder;
    use NormalisesFeaturedImage;

    protected static string $resource = PostResource::class;

    protected string $view = 'blog::filament.post-editor';

    public bool $isEditMode = true;

    /** Status forced by the Save Draft / Publish buttons; null for autosave. */
    public ?string $forcedStatus = null;

    /** Reviewer feedback captured on the "send back" transition. */
    public ?string $reviewNote = null;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Authoritative open-time gate: permission + ownership. Blocks an author
        // from reaching another author's builder by editing the {record} in the
        // URL or forging the mount, independent of Filament's own access checks.
        abort_unless(PostAccess::canEdit(auth()->user(), $this->record), 403);

        $this->seedSidebarState();
    }

    /**
     * Whether the current user may publish THIS post: publish permission plus
     * ownership (or edit-others). Overrides the trait's permission-only check so
     * the status clamp, the Publish button and the publish action are all
     * ownership-aware — an author with publish rights cannot publish another
     * author's post.
     */
    public function userCanPublish(): bool
    {
        return PostAccess::canPublish(auth()->user(), $this->record);
    }

    /**
     * Re-assert edit authority on every mutating Livewire action. mount() already
     * gates opening the page, but the action methods are independently callable,
     * so each verifies permission + ownership server-side rather than trusting the
     * mounted state.
     */
    protected function authorizeEdit(): void
    {
        abort_unless(PostAccess::canEdit(auth()->user(), $this->record), 403);
    }

    protected function getSavedNotification(): ?Notification
    {
        return null;
    }

    public function saveDraft(): void
    {
        $this->authorizeEdit();
        $this->forcedStatus = PostStatus::Draft->value;
        $this->save(shouldRedirect: false);
        // Keep the form in sync with the saved status so a following autosave
        // (which has no forced status) does not revert it.
        $this->data['status'] = PostStatus::Draft->value;
        $this->editorStatus = 'draft_saved';
        Notification::make()->title(__('blog::builder.notifications.draft_saved'))->success()->send();
    }

    public function publish(): void
    {
        // Server-side guard: hiding the button is not enough — a forged Livewire
        // call must also be refused. userCanPublish() here is ownership-aware.
        $this->authorizeEdit();
        abort_unless($this->userCanPublish(), 403);

        $wasPublished = $this->record->status === PostStatus::Published;

        $this->forcedStatus = PostStatus::Published->value;
        $this->save(shouldRedirect: false);
        $this->data['status'] = PostStatus::Published->value;
        $this->editorStatus = 'published';

        $message = (string) ($wasPublished
            ? __('blog::builder.notifications.updated')
            : __('blog::builder.notifications.published'));
        $this->dispatch('blog-published', message: $message);
        Notification::make()->title($message)->success()->send();
    }

    /**
     * Contributor action: hand the post to editors for review. Available to
     * anyone who can edit; it never publishes.
     */
    public function submitForReview(): void
    {
        $this->authorizeEdit();
        $this->forcedStatus = PostStatus::PendingReview->value;
        $this->save(shouldRedirect: false);
        $this->data['status'] = PostStatus::PendingReview->value;
        $this->editorStatus = 'pending';
        Notification::make()->title(__('blog::builder.notifications.submitted_for_review'))->success()->send();
    }

    /**
     * Reviewer action: send a submitted post back to draft with a note. Only a
     * user who can publish may request changes.
     */
    public function sendBack(string $note = ''): void
    {
        $this->authorizeEdit();
        abort_unless($this->userCanPublish(), 403);

        $this->forcedStatus = PostStatus::Draft->value;
        $this->reviewNote = trim($note);
        $this->save(shouldRedirect: false);
        $this->data['status'] = PostStatus::Draft->value;
        $this->editorStatus = 'draft_saved';
        Notification::make()->title(__('blog::builder.notifications.sent_back'))->success()->send();
    }

    public function deletePost(): void
    {
        // Deletion needs the delete permission AND authority over this post — the
        // builder's own button must be gated exactly like the table DeleteAction.
        abort_unless(PostAccess::canDelete(auth()->user(), $this->record), 403);

        $this->record->delete(); // Soft delete = Trash.
        Notification::make()->title(__('blog::builder.notifications.trashed'))->success()->send();
        $this->redirect(PostResource::getUrl('index'));
    }

    /** Silent background save triggered by the editor autosave loop. */
    public function autosave(): void
    {
        $this->authorizeEdit();
        $this->forcedStatus = null;
        // Mark this write as an autosave so PostObserver skips the historical
        // revision and the blog.post.updated webhook (draft state is still saved).
        $this->record->autosaving = true;
        $this->save(shouldRedirect: false);
        $this->editorStatus = 'draft_saved';
        $this->dispatch('blog-autosaved');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = $this->normaliseFeaturedImageForForm($data);
        $data['tags'] = $this->record->tags()->pluck('blog_tags.id')->all();
        $data['meta'] = $this->metaFormRows();
        $this->editorStatus = $this->record->status === PostStatus::Published ? 'published' : 'draft_saved';

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = array_merge($data, $this->sidebarForm->getState());

        // Save Draft / Publish buttons win over the sidebar select. The result is
        // clamped to what the user may set: without blog.posts.publish, a
        // publish-tier status (Published / Scheduled / Archived) from the select,
        // the forcedStatus property or a forged payload is refused server-side.
        $data['status'] = PostWorkflow::resolveStatus(
            $this->forcedStatus,
            $data['status'] ?? null,
            $this->userCanPublish(),
            $this->record->status->value,
        );

        // Featuring surfaces a post through the delivery API, so it is an
        // editorial (publish-tier) act: a user without publish permission cannot
        // change the flag — their save keeps whatever it already is.
        $data['is_featured'] = $this->userCanPublish()
            ? (bool) ($data['is_featured'] ?? false)
            : $this->record->is_featured;

        $data['review_note'] = PostWorkflow::reviewNote(
            (string) $data['status'],
            $this->reviewNote,
            $this->record->review_note,
        );

        // Shared form-shaping (defaults, publish date, slug, sanitised content,
        // library image). Slug ignores this record so a kept slug stays stable.
        return $this->normaliseFeaturedImageForSave(
            $this->normalisePostForm($data, $this->record->getKey()),
        );
    }

    protected function afterSave(): void
    {
        $tags = $this->data['tags'] ?? [];
        $this->record->tags()->sync(is_array($tags) ? array_map('intval', $tags) : []);
        $this->syncPostMeta();
        $this->syncCoAuthors();
        $this->syncSeoMeta();
    }
}
