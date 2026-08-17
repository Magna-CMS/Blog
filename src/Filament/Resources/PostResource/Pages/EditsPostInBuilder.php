<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Resources\PostResource\Pages;

use Filament\Forms\Components\DateTimePicker;
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
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Models\Series;
use MagnaCms\Blog\Models\Tag;
use MagnaCms\Blog\Seo\PostSeoMeta;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\Locales;
use MagnaCms\Blog\Support\SlugGenerator;

/**
 * Shared canvas + sidebar schema and media-picker wiring for the full-screen
 * post builder, used by both the create and edit pages so the two stay in sync.
 * The layout mirrors the Magna Docs page builder; the only content difference is
 * the Editor.js canvas in place of the Markdown editor.
 */
trait EditsPostInBuilder
{
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
            $this->data['featured_image'] ??= $record->featured_image;
            $this->data['published_at'] ??= $record->published_at?->format('Y-m-d H:i:s');
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

    /** Sidebar: publishing, permalink, featured image, organisation. */
    public function sidebarForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Summary')
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options(PostStatus::options())
                            ->default(PostStatus::Draft->value)
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->live(),

                        Select::make('visibility')
                            ->label('Visibility')
                            ->options(PostVisibility::options())
                            ->default(PostVisibility::Public->value)
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->live(),

                        TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('visibility') === PostVisibility::Password->value)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Leave blank when editing to keep the current password.'),

                        DateTimePicker::make('published_at')
                            ->label('Publish date')
                            ->seconds(false)
                            ->visible(fn (Get $get): bool => in_array(
                                $get('status'),
                                [PostStatus::Published->value, PostStatus::Scheduled->value],
                                true,
                            )),

                        Toggle::make('is_featured')
                            ->label('Feature this post')
                            ->helperText('Featured posts can be pulled out via the delivery API.')
                            ->default(false),

                        Placeholder::make('_review_note')
                            ->label('Changes requested')
                            ->visible(fn (): bool => ($post = $this->currentPost()) !== null
                                && filled($post->review_note)
                                && $post->status !== PostStatus::Published)
                            ->content(fn (): HtmlString => new HtmlString(
                                '<div style="color:var(--de-danger,#dc2626);font-size:.8rem;line-height:1.4">'
                                .e((string) $this->currentPost()?->review_note).'</div>'
                            )),

                        Placeholder::make('_author')
                            ->label('Author')
                            ->content(fn (): string => auth()->user()->name ?? 'Admin'),

                        Select::make('co_authors')
                            ->label('Co-authors')
                            ->multiple()
                            ->searchable()
                            ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder('Add contributors')
                            ->helperText('Extra bylines shown alongside the primary author.'),
                    ]),

                Section::make('Permalink')
                    ->collapsible()
                    ->schema([
                        TextInput::make('slug')
                            ->hiddenLabel()
                            ->prefix('/blog/')
                            ->placeholder('post-slug')
                            ->helperText('Auto-generated from the title; uniqueness is enforced on save.'),
                    ]),

                Section::make('Featured Image')
                    ->collapsible()
                    ->schema([
                        FileUpload::make('featured_image')
                            ->hiddenLabel()
                            ->image()
                            ->disk('public')
                            ->directory('blog-featured')
                            ->imageResizeMode('cover')
                            ->imageCropAspectRatio('16:9')
                            ->imageResizeTargetWidth('1200')
                            ->imageResizeTargetHeight('675')
                            ->panelAspectRatio('16:9')
                            ->panelLayout('integrated')
                            ->visible(fn (): bool => blank($this->libraryImageUrl)),

                        Placeholder::make('_library_preview')
                            ->hiddenLabel()
                            ->visible(fn (): bool => filled($this->libraryImageUrl))
                            ->content(fn (): HtmlString => new HtmlString(
                                '<div class="de-featured-preview">'
                                .'<img src="'.e((string) $this->libraryImageUrl).'" alt="Featured image">'
                                .'<button type="button" class="de-featured-preview-remove" wire:click="clearLibraryImage" title="Remove image">&times;</button>'
                                .'<span class="de-featured-preview-tag">From media library</span>'
                                .'</div>'
                            )),

                        Placeholder::make('_browse_media')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<button type="button" class="de-browse-link" onclick="Livewire.dispatch(\'magna:open-media-picker\', { target: \'featured-image\' })">'
                                .'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
                                .'Browse media library</button>'
                            )),
                    ]),

                Section::make('Organisation')
                    ->collapsible()
                    ->schema([
                        Select::make('category_id')
                            ->label('Category')
                            ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->placeholder('— none —')
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),
                                TextInput::make('slug')->required()->unique('blog_categories', 'slug'),
                            ])
                            // Assigning a category is an edit-level act; CREATING
                            // one is taxonomy management, so gate the inline create.
                            ->createOptionUsing(function (array $data): int {
                                abort_unless(auth()->user()?->can('blog.categories.manage') ?? false, 403);

                                return Category::create($data)->id;
                            }),

                        Select::make('tags')
                            ->label('Tags')
                            ->multiple()
                            ->searchable()
                            ->options(fn (): array => Tag::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->createOptionForm([
                                TextInput::make('name')->required(),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                abort_unless(auth()->user()?->can('blog.tags.manage') ?? false, 403);

                                return Tag::create([
                                    'name' => $data['name'],
                                    'slug' => Str::slug((string) $data['name']),
                                ])->id;
                            }),

                        Select::make('series_id')
                            ->label('Series')
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: 'A series groups related posts into an ordered set (e.g. a tutorial in parts). '
                                    .'Pick a series and set the part number; the frontend then shows "Part N of M" with previous/next links. Optional.',
                            )
                            ->options(fn (): array => Series::query()->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->placeholder('— none —')
                            ->live()
                            ->createOptionForm([
                                TextInput::make('title')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),
                                TextInput::make('slug')->required()->unique('blog_series', 'slug'),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                abort_unless(auth()->user()?->can('blog.series.manage') ?? false, 403);

                                return Series::create($data)->id;
                            }),

                        TextInput::make('series_position')
                            ->label('Part number')
                            ->numeric()
                            ->minValue(1)
                            ->visible(fn (Get $get): bool => filled($get('series_id')))
                            ->helperText('Order of this post within the series.'),

                        Select::make('locale')
                            ->label('Language')
                            ->options(fn (): array => Locales::all())
                            ->searchable()
                            ->default(fn (): string => BlogSettings::get()->default_locale ?: 'en')
                            ->native(false)
                            ->selectablePlaceholder(false),

                        TextInput::make('translation_group')
                            ->label('Translation group')
                            ->maxLength(40)
                            ->helperText('Give the same key to posts that are translations of each other.'),
                    ]),

                Section::make('Discussion')
                    ->collapsible()
                    ->schema([
                        Toggle::make('allow_comments')
                            ->label('Allow comments on this post')
                            ->default(true)
                            ->helperText('Overridden when comments are disabled site-wide in Blog settings.'),
                    ]),

                Section::make('Custom Fields')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Repeater::make('meta')
                            ->hiddenLabel()
                            ->addActionLabel('Add custom field')
                            ->schema([
                                TextInput::make('key')
                                    ->label('Key')
                                    ->required()
                                    ->maxLength(190)
                                    ->rule('regex:/^[A-Za-z0-9_.:-]+$/')
                                    ->helperText('Letters, numbers, and _ . : - only.'),

                                Select::make('type')
                                    ->label('Type')
                                    ->options(MetaType::options())
                                    ->default(MetaType::String->value)
                                    ->native(false)
                                    ->selectablePlaceholder(false),

                                Textarea::make('value')
                                    ->label('Value')
                                    ->rows(2)
                                    ->helperText('For the JSON type, enter valid JSON.'),
                            ])
                            ->itemLabel(fn (array $state): ?string => $state['key'] ?? null)
                            ->columns(1)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->helperText('Custom fields are private by default; expose keys in Blog settings to serve them via the API.'),
                    ]),
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
