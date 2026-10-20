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
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Magna\Users\User;
use MagnaCms\Blog\Enums\MetaType;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Series;
use MagnaCms\Blog\Models\Tag;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\Locales;
use MagnaCms\Blog\Support\PostPermalink;

/**
 * The post builder's sidebar schema — publishing, excerpt, permalink,
 * featured image, organisation, discussion and custom fields — taken out of
 * EditsPostInBuilder (which is pinned in core's PluginArchitectureTest) so
 * the builder's canvas, state seeding and persistence stay readable on
 * their own. Used through EditsPostInBuilder by the create and edit pages.
 */
trait PostSidebarSections
{
    /** Sidebar: publishing, excerpt, permalink, featured image, organisation. */
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

                Section::make('Excerpt')
                    ->collapsible()
                    ->schema([
                        Textarea::make('excerpt')
                            ->hiddenLabel()
                            ->rows(3)
                            ->maxLength(300)
                            ->required(fn (): bool => $this->excerptRequired())
                            ->placeholder('A sentence or two on what this post is about')
                            ->helperText('Shown in post lists and as the search description. Left empty, the first paragraph is used.'),
                    ]),

                Section::make('Permalink')
                    ->collapsible()
                    ->schema([
                        TextInput::make('slug')
                            ->hiddenLabel()
                            ->prefix(fn (): string => PostPermalink::fromSettings()->staticPrefix())
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
     * An excerpt is required when Blog settings say posts may not go without
     * one — on an explicit save (Save draft, Publish, Submit for review),
     * never on a background autosave, which would otherwise fail silently
     * every few seconds while the author is still writing.
     */
    protected function excerptRequired(): bool
    {
        return $this->forcedStatus !== null && ! BlogSettings::get()->allow_empty_excerpt;
    }
}
