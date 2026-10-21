<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Resources\PostResource\Pages;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Magna\Users\User;
use MagnaCms\Blog\Editor\EditorJsSanitizer;
use MagnaCms\Blog\Enums\MetaType;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Filament\Fields\EditorJsField;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Seo\PostSeoMeta;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\SlugGenerator;

/**
 * Shared canvas schema, sidebar state and media-picker wiring for the full-screen
 * post builder, used by both the create and edit pages so the two stay in sync.
 * The layout mirrors the Magna Docs page builder; the only content difference is
 * the Editor.js canvas in place of the Markdown editor. The sidebar's schema
 * itself lives in PostSidebarSections.
 */
trait EditsPostInBuilder
{
    use PostSidebarSections;

    public string $editorStatus = '';

    /**
     * Whether the current user may publish or approve posts. Without it the
     * builder offers "Submit for review" instead of publishing directly, and the
     * server-side publish/approve/send-back actions refuse the request.
     */
    public function userCanPublish(): bool
    {
        return auth()->user()?->can('blog.posts.publish') ?? false;
    }

    /**
     * The persisted post backing this builder, or null before the first save on
     * the create page. Resolved via Filament's getRecord() so it is safe whether
     * this page is editing an existing post or a create page that has not yet
     * persisted its draft.
     */
    protected function currentPost(): ?Post
    {
        $record = $this->getRecord();

        return $record instanceof Post ? $record : null;
    }

    /**
     * Seed every sidebar-form field into $data so Livewire can entangle them.
     *
     * The canvas and sidebar are two separate schemas sharing the `data` state
     * path, but Filament's mount only fills the canvas form — leaving the sidebar
     * keys absent, which makes each sidebar field throw a Livewire "property
     * cannot be found" entangle error and breaks the whole sidebar (and, via the
     * cascading error, the editor). `??=` preserves anything the canvas fill
     * already set (tags, meta and the normalised featured image on edit).
     */
    protected function seedSidebarState(): void
    {
        if ($this->isEditMode) {
            $record = $this->record;
            $this->data['status'] ??= $record->status->value;
            $this->data['visibility'] ??= $record->visibility->value;
            $this->data['category_id'] ??= $record->category_id;
            $this->data['series_id'] ??= $record->series_id;
            $this->data['series_position'] ??= $record->series_position;
            $this->data['co_authors'] ??= $record->coAuthors()->pluck('users.id')->all();
            $this->data['locale'] ??= $record->locale;
            $this->data['translation_group'] ??= $record->translation_group;
            $this->data['is_featured'] ??= $record->is_featured;
            $this->data['allow_comments'] ??= $record->allow_comments;
            $this->data['excerpt'] ??= $record->excerpt;
            $this->data['featured_image'] ??= $record->featured_image;
            $this->data['published_at'] = $this->publishDateState($this->data['published_at'] ?? $record->published_at);
        } else {
            $this->data['status'] ??= PostStatus::Draft->value;
            $this->data['visibility'] ??= PostVisibility::Public->value;
            $this->data['category_id'] ??= null;
            $this->data['series_id'] ??= null;
            $this->data['series_position'] ??= null;
            $this->data['co_authors'] ??= [];
            $this->data['locale'] ??= (BlogSettings::get()->default_locale ?: 'en');
            $this->data['translation_group'] ??= null;
            $this->data['is_featured'] ??= false;
            $this->data['allow_comments'] ??= true;
            $this->data['excerpt'] ??= null;
            $this->data['featured_image'] ??= null;
            $this->data['published_at'] ??= null;
        }

        $this->data['password'] ??= null;
        $this->data['tags'] ??= [];
        $this->data['meta'] ??= [];

        $this->seedSeoState();
    }

    /**
     * Seed the SEO tab's state (statePath 'seo'). Only meaningful when the SEO
     * plugin is active; otherwise the tab shows a placeholder and needs no state.
     * On edit the post's stored overrides are loaded; on create the blank shape
     * (indexable by default) is used.
     */
    protected function seedSeoState(): void
    {
        if (! PostSeoMeta::active()) {
            return;
        }

        $values = $this->isEditMode
            ? PostSeoMeta::read($this->record)
            : PostSeoMeta::blank();

        // SEO fields ride as flat, seo_-prefixed keys in $this->data — the same
        // state bag (and shape) as the sidebar's status / tags / meta. Filament
        // preserves top-level data keys across EditRecord's hydration but drops a
        // nested sub-array, so a flat prefix is what keeps them across a save.
        // `??=` keeps anything the user has already entered.
        foreach ($values as $key => $value) {
            $this->data['seo_'.$key] ??= $value;
        }
    }

    /** Path/URL of an image chosen from the media library (not via FileUpload). */
    public ?string $libraryImagePath = null;

    public ?string $libraryImageUrl = null;

    /** Canvas: title + Editor.js content. */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(1)
            ->components([
                TextInput::make('title')
                    ->hiddenLabel()
                    ->required()
                    ->placeholder('Add title')
                    ->live(onBlur: true)
                    ->extraInputAttributes(['class' => 'doc-editor-title-input', 'autocomplete' => 'off'])
                    ->afterStateUpdated(function (?string $state, callable $set, ?string $old, Get $get): void {
                        $currentSlug = $get('slug');
                        if (blank($currentSlug) || $currentSlug === Str::slug($old ?? '')) {
                            $set('slug', Str::slug($state ?? ''));
                        }
                    }),

                EditorJsField::make('content')
                    ->hiddenLabel()
                    ->editorPlaceholder('Write your story…'),
            ]);
    }

    /**
     * SEO sidebar tab. When the optional Magna SEO plugin is active this edits the
     * post's SEO overrides (search appearance, robots, Open Graph, Twitter),
     * persisted to that plugin's meta store on save. When it is absent the tab
     * shows a single placeholder telling the user how to enable it — the blog
     * never hard-depends on SEO (see PostSeoMeta).
     */
    public function seoForm(Schema $schema): Schema
    {
        if (! PostSeoMeta::active()) {
            return $schema->components([
                Placeholder::make('_seo_unavailable')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div style="padding:1.25rem 0;color:var(--de-text-muted);font-size:.82rem;line-height:1.5">'
                        .'<strong style="display:block;color:var(--de-text);margin-bottom:.35rem">SEO tools not available</strong>'
                        .'Install and enable the <strong>Magna SEO</strong> plugin to manage this post’s search '
                        .'title, meta description, canonical URL and social preview.'
                        .'</div>'
                    )),
            ]);
        }

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Search appearance')
                    ->schema([
                        TextInput::make('seo_title')
                            ->label('SEO title')
                            ->maxLength(70)
                            ->placeholder('Defaults to the post title')
                            ->helperText('Around 50–60 characters shows fully in Google.'),

                        Textarea::make('seo_description')
                            ->label('Meta description')
                            ->rows(3)
                            ->maxLength(300)
                            ->placeholder('Defaults to the excerpt')
                            ->helperText('Around 150–160 characters is ideal.'),

                        TextInput::make('seo_canonical_url')
                            ->label('Canonical URL')
                            ->url()
                            ->maxLength(255)
                            ->placeholder('Leave blank to use this post’s URL'),

                        TextInput::make('seo_focus_keyword')
                            ->label('Focus keyword')
                            ->maxLength(191)
                            ->helperText('The main phrase you want this post to rank for.'),
                    ]),

                Section::make('Search engines')
                    ->schema([
                        Toggle::make('seo_robots_index')
                            ->label('Allow search engines to index this post')
                            ->default(true),

                        Toggle::make('seo_robots_follow')
                            ->label('Allow search engines to follow its links')
                            ->default(true),
                    ]),

                Section::make('Open Graph (Facebook, LinkedIn)')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextInput::make('seo_og_title')
                            ->label('Title')
                            ->maxLength(120)
                            ->placeholder('Defaults to the SEO title'),

                        Textarea::make('seo_og_description')
                            ->label('Description')
                            ->rows(2)
                            ->maxLength(300)
                            ->placeholder('Defaults to the meta description'),

                        Placeholder::make('_og_image_note')
                            ->hiddenLabel()
                            ->content('The social share image uses this post’s featured image.'),
                    ]),

                Section::make('Twitter / X')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Select::make('seo_twitter_card')
                            ->label('Card type')
                            ->options([
                                'summary' => 'Summary',
                                'summary_large_image' => 'Summary with large image',
                            ])
                            ->native(false)
                            ->placeholder('Site default'),

                        TextInput::make('seo_twitter_title')
                            ->label('Title')
                            ->maxLength(120)
                            ->placeholder('Defaults to the Open Graph title'),

                        Textarea::make('seo_twitter_description')
                            ->label('Description')
                            ->rows(2)
                            ->maxLength(300)
                            ->placeholder('Defaults to the Open Graph description'),
                    ]),
            ]);
    }

    /**
     * Persist the SEO tab to the SEO plugin's meta store. A no-op when that plugin
     * is absent, so the (never-shown) fields simply drop. Called after the post
     * row is saved so the meta always attaches to a real post id.
     */
    protected function syncSeoMeta(): void
    {
        if (! PostSeoMeta::active()) {
            return;
        }

        // Collect the flat seo_-prefixed fields back into the clean shape the SEO
        // bridge expects. blank() supplies both the key set and the fallbacks.
        $payload = [];
        foreach (PostSeoMeta::blank() as $key => $default) {
            $payload[$key] = $this->data['seo_'.$key] ?? $default;
        }
        PostSeoMeta::write($this->record, $payload);
    }

    /**
     * Rows the meta Repeater should show when the builder loads, one per stored
     * meta key. JSON values are re-encoded for the textarea; scalars are shown as
     * plain strings.
     *
     * @return list<array{key: string, type: string, value: string}>
     */
    protected function metaFormRows(): array
    {
        $rows = [];

        foreach ($this->record->meta as $meta) {
            $value = $meta->value;
            $display = $meta->type === MetaType::Json
                ? (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : (is_scalar($value) ? (string) $value : '');

            $rows[] = ['key' => $meta->key, 'type' => $meta->type->value, 'value' => $display];
        }

        return $rows;
    }

    /**
     * Form-shaping shared by the create and edit builder pages: scalar defaults,
     * the publish-date default, a unique slug, sanitised content and the
     * media-library featured-image fallback.
     *
     * The security-sensitive fields — the status clamp, authorship and the
     * featured flag — are intentionally NOT set here. Each page applies those
     * explicitly (create forces authorship and un-features for non-publishers;
     * edit preserves both), so the differing rules stay visible at the call site
     * rather than hiding behind a shared flag. Callers pass an already-resolved
     * status in $data so the publish-date default sees it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalisePostForm(array $data, int|string|null $ignoreId): array
    {
        $data['visibility'] ??= PostVisibility::Public->value;
        $data['allow_comments'] ??= true;

        if (($data['status'] ?? null) === PostStatus::Published->value && blank($data['published_at'] ?? null)) {
            $data['published_at'] = now()->toDateTimeString();
        }

        $source = filled($data['slug'] ?? null) ? (string) $data['slug'] : (string) ($data['title'] ?? '');
        $data['slug'] = app(SlugGenerator::class)->generate($source, 'blog_posts', $ignoreId);

        $data['content'] = app(EditorJsSanitizer::class)->sanitize($data['content'] ?? null);

        if (blank($data['featured_image'] ?? null) && $this->libraryImagePath !== null) {
            $data['featured_image'] = $this->libraryImagePath;
        }

        return $data;
    }

    /**
     * Sync the post's co-authors from the multi-select, excluding the primary
     * author so a byline is never duplicated.
     */
    protected function syncCoAuthors(): void
    {
        $ids = is_array($this->data['co_authors'] ?? null) ? $this->data['co_authors'] : [];
        $ids = array_values(array_filter(
            array_map('strval', $ids),
            fn (string $id): bool => $id !== '' && $id !== (string) $this->record->author_id,
        ));

        $this->record->coAuthors()->sync($ids);
    }

    /**
     * Persist the meta Repeater rows to blog_post_meta: upsert each named key and
     * remove any key no longer present. Runs in a transaction so a post never ends
     * up with a half-written custom-field set. Blank keys are skipped.
     */
    protected function syncPostMeta(): void
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($this->data['meta'] ?? null) ? $this->data['meta'] : [];

        DB::transaction(function () use ($rows): void {
            $kept = [];

            foreach ($rows as $row) {
                $key = trim((string) ($row['key'] ?? ''));
                if ($key === '') {
                    continue;
                }

                $type = MetaType::tryFrom((string) ($row['type'] ?? '')) ?? MetaType::String;

                $this->record->meta()->updateOrCreate(
                    ['key' => $key],
                    ['type' => $type->value, 'value' => $this->decodeMetaValue($type, $row['value'] ?? null)],
                );

                $kept[] = $key;
            }

            $this->record->meta()->whereNotIn('key', $kept)->delete();
        });
    }

    /**
     * Normalise a raw textarea value for storage. JSON is decoded so it round-trips
     * as structured data (falling back to the raw string when it is not valid
     * JSON); every other type is stored as a plain string and coerced on read.
     */
    private function decodeMetaValue(MetaType $type, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        if ($type === MetaType::Json) {
            $decoded = json_decode((string) $raw, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $raw;
        }

        return (string) $raw;
    }

    #[On('magna:media-selected')]
    public function onMediaSelected(string $path, string $url, string $disk, string $target): void
    {
        if ($target === 'featured-image') {
            $this->selectMediaFile($path, $disk);
        }
    }

    public function selectMediaFile(string $path, string $disk = 'public'): void
    {
        $this->libraryImagePath = $path;
        $this->libraryImageUrl = Storage::disk($disk)->url($path);
    }

    public function clearLibraryImage(): void
    {
        $this->libraryImagePath = null;
        $this->libraryImageUrl = null;
    }
}
