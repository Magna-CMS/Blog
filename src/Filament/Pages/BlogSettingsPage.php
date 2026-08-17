<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Magna\Users\User;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\Locales;

/**
 * @property Schema $form
 */
class BlogSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 99;

    protected static ?string $slug = 'blog-settings';

    public static function getNavigationGroup(): ?string
    {
        return (string) __('blog::resources.group');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('blog::settings.navigation');
    }

    public function getTitle(): string
    {
        return (string) __('blog::settings.title');
    }

    protected string $view = 'blog::filament.settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('blog.settings.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = BlogSettings::get();

        $this->form->fill([
            'default_status' => $settings->default_status,
            'default_author_id' => $settings->default_author_id,
            'posts_per_page' => $settings->posts_per_page,
            'enable_scheduling' => $settings->enable_scheduling,
            'enable_revisions' => $settings->enable_revisions,
            'max_revisions' => $settings->max_revisions,
            'auto_generate_slug' => $settings->auto_generate_slug,
            'allow_manual_slug' => $settings->allow_manual_slug,
            'permalink_pattern' => $settings->permalink_pattern,
            'autosave_interval' => $settings->autosave_interval,
            'default_category_id' => $settings->default_category_id,
            'default_locale' => $settings->default_locale,
            'allow_empty_excerpt' => $settings->allow_empty_excerpt,
            'generate_reading_time' => $settings->generate_reading_time,
            'enable_comments' => $settings->enable_comments,
            'require_login_to_comment' => $settings->require_login_to_comment,
            'comment_moderation' => $settings->comment_moderation,
            'comments_close_after_days' => $settings->comments_close_after_days,
            'public_meta' => $settings->public_meta,
            'custom_fonts' => array_values(array_map(
                static fn (array $f): array => [
                    'label' => $f['label'] ?? '',
                    'file' => $f['path'] ?? '',
                ],
                $settings->custom_fonts,
            )),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('General')
                    ->columns(2)
                    ->schema([
                        Select::make('default_status')
                            ->options(PostStatus::options())
                            ->required(),
                        Select::make('default_author_id')
                            ->label('Default author')
                            ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->placeholder('— none —'),
                        TextInput::make('posts_per_page')->numeric()->minValue(1)->maxValue(100)->required(),
                        Toggle::make('enable_scheduling'),
                        Toggle::make('enable_revisions'),
                        TextInput::make('max_revisions')->numeric()->minValue(1)->maxValue(200)->required(),
                    ]),

                Section::make('Slugs')
                    ->columns(2)
                    ->schema([
                        Toggle::make('auto_generate_slug'),
                        Toggle::make('allow_manual_slug'),
                    ]),

                Section::make('Permalinks')
                    ->description('Stored for future frontend plugins only — no routing is applied here.')
                    ->schema([
                        TextInput::make('permalink_pattern')
                            ->helperText('e.g. /blog/{slug}, /{slug}, /blog/{year}/{slug}, /blog/{category}/{slug}'),
                    ]),

                Section::make('Editor')
                    ->schema([
                        TextInput::make('autosave_interval')
                            ->label('Autosave interval (seconds)')
                            ->numeric()->minValue(2)->maxValue(120)->required(),
                    ]),

                Section::make('Fonts')
                    ->description('Upload custom fonts to use across the editor (buttons and more). Popular free fonts are already built in.')
                    ->schema([
                        Repeater::make('custom_fonts')
                            ->label('Custom fonts')
                            ->addActionLabel('Upload a font')
                            ->reorderable(false)
                            ->schema([
                                TextInput::make('label')
                                    ->label('Name')
                                    ->placeholder('e.g. Brand Sans')
                                    ->required()
                                    ->maxLength(60),
                                FileUpload::make('file')
                                    ->label('Font file (woff2, woff, ttf, otf)')
                                    ->disk('public')
                                    ->directory('blog/fonts')
                                    ->acceptedFileTypes([
                                        'font/woff2', 'font/woff', 'font/ttf', 'font/otf',
                                        'application/font-woff', 'application/x-font-ttf',
                                        'application/octet-stream',
                                    ])
                                    ->maxSize(4096)
                                    ->required(),
                            ])
                            ->columns(2),
                    ]),

                Section::make('Content')
                    ->columns(2)
                    ->schema([
                        Select::make('default_category_id')
                            ->label('Default category')
                            ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->placeholder('— none —'),
                        Select::make('default_locale')
                            ->label('Default language')
                            ->options(fn (): array => Locales::all())
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->helperText('Pre-selected as the language for every new post.'),
                        Toggle::make('allow_empty_excerpt'),
                        Toggle::make('generate_reading_time'),
                    ]),

                Section::make('Custom Fields')
                    ->description('Per-post custom fields are private by default. List the meta keys that may be served through the delivery API here.')
                    ->schema([
                        TagsInput::make('public_meta')
                            ->label('Public meta keys')
                            ->placeholder('Add a key')
                            ->helperText('Only these keys are exposed in the public post payload.'),
                    ]),

                Section::make('Comments')
                    ->description('Backend flags for future frontend comment plugins. New posts default to allowing comments unless disabled per post.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('enable_comments')
                            ->label('Enable comments site-wide')
                            ->helperText('Master switch. When off, no post accepts comments.'),
                        Toggle::make('require_login_to_comment')
                            ->label('Require login to comment'),
                        Select::make('comment_moderation')
                            ->label('New comment handling')
                            ->options([
                                'pending' => 'Hold for moderation',
                                'auto' => 'Auto-approve',
                            ])
                            ->native(false)
                            ->required(),
                        TextInput::make('comments_close_after_days')
                            ->label('Close comments after (days)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(3650)
                            ->helperText('0 = never close.')
                            ->required(),
                    ]),
            ]);
    }

    public function save(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->form->getState();

        $settings = BlogSettings::get();
        $settings->default_status = (string) $data['default_status'];
        $settings->default_author_id = $data['default_author_id'] ?: null;
        $settings->posts_per_page = (int) $data['posts_per_page'];
        $settings->enable_scheduling = (bool) $data['enable_scheduling'];
        $settings->enable_revisions = (bool) $data['enable_revisions'];
        $settings->max_revisions = (int) $data['max_revisions'];
        $settings->auto_generate_slug = (bool) $data['auto_generate_slug'];
        $settings->allow_manual_slug = (bool) $data['allow_manual_slug'];
        $settings->permalink_pattern = (string) $data['permalink_pattern'];
        $settings->autosave_interval = (int) $data['autosave_interval'];
        $settings->default_category_id = $data['default_category_id'] ? (int) $data['default_category_id'] : null;
        $settings->default_locale = Locales::has((string) $data['default_locale']) ? (string) $data['default_locale'] : 'en';
        $settings->allow_empty_excerpt = (bool) $data['allow_empty_excerpt'];
        $settings->generate_reading_time = (bool) $data['generate_reading_time'];
        $settings->enable_comments = (bool) $data['enable_comments'];
        $settings->require_login_to_comment = (bool) $data['require_login_to_comment'];
        $settings->comment_moderation = (string) $data['comment_moderation'];
        $settings->comments_close_after_days = (int) $data['comments_close_after_days'];
        $settings->public_meta = $this->normalisePublicMeta($data['public_meta'] ?? []);
        $settings->custom_fonts = $this->normaliseCustomFonts($data['custom_fonts'] ?? []);
        $settings->save();

        Notification::make()->title(__('blog::settings.saved'))->success()->send();
    }

    /**
     * Sanitise the public-meta allowlist: trim, drop blanks, keep only safe key
     * characters, and de-duplicate. Bad input can never widen exposure to keys an
     * author did not name.
     *
     * @return list<string>
     */
    private function normalisePublicMeta(mixed $keys): array
    {
        if (! is_array($keys)) {
            return [];
        }

        $clean = [];
        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }

            $key = trim($key);
            if ($key !== '' && preg_match('/^[A-Za-z0-9_.:-]+$/', $key) === 1) {
                $clean[$key] = $key;
            }
        }

        return array_values($clean);
    }

    /**
     * Turn the repeater rows into stored font records:
     * ['key','label','path','url','format']. Each label yields a stable key; the
     * uploaded file's public URL + @font-face format are derived from its path.
     *
     * @return array<int, array<string, string>>
     */
    private function normaliseCustomFonts(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $fonts = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim(is_string($row['label'] ?? null) ? $row['label'] : '');
            $file = $row['file'] ?? null;
            $path = is_array($file) ? (string) (reset($file) ?: '') : (string) ($file ?? '');
            if ($label === '' || $path === '') {
                continue;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $format = match ($extension) {
                'woff' => 'woff',
                'ttf' => 'truetype',
                'otf' => 'opentype',
                default => 'woff2',
            };

            $fonts[] = [
                'key' => 'u-'.Str::slug($label),
                'label' => $label,
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'format' => $format,
            ];
        }

        return $fonts;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label((string) __('blog::settings.save'))
                ->icon('heroicon-o-check')
                ->action(fn () => $this->save()),
        ];
    }
}
