<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Resources;

use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use MagnaCms\Blog\Filament\Resources\CategoryResource\Pages;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Support\DefaultCategory;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return (string) __('blog::resources.group');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('blog::resources.category.navigation');
    }

    public static function getModelLabel(): string
    {
        return (string) __('blog::resources.category.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('blog::resources.category.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('blog.categories.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('blog.categories.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('blog.categories.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        // The Uncategorised fallback category can never be deleted.
        if ($record instanceof Category && DefaultCategory::isDefault($record)) {
            return false;
        }

        return auth()->user()?->can('blog.categories.manage') ?? false;
    }

    /**
     * A WordPress-style hover submenu: All Categories / New Category.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (! (auth()->user()?->can('blog.categories.manage') ?? false)) {
            return [];
        }

        $base = static::getRouteBaseName();

        return [
            NavigationItem::make((string) __('blog::resources.category.navigation'))
                ->group((string) __('blog::resources.group'))
                ->icon('heroicon-o-folder')
                ->sort(2)
                ->url('')
                ->childItems([
                    NavigationItem::make((string) __('blog::resources.category.nav.all'))
                        ->url(static::getUrl('index'))
                        ->icon('heroicon-m-list-bullet')
                        ->isActiveWhen(fn (): bool => request()->routeIs($base.'.index') && request()->query('action') !== 'create'),

                    NavigationItem::make((string) __('blog::resources.category.nav.new'))
                        ->url(static::getUrl('index').'?action=create')
                        ->icon('heroicon-m-plus-circle')
                        ->isActiveWhen(fn (): bool => request()->routeIs($base.'.index') && request()->query('action') === 'create'),
                ]),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->unique(table: 'blog_categories', column: 'slug', ignoreRecord: true)
                ->helperText('Auto-filled from the name; edit if needed.'),

            FileUpload::make('image')
                ->label('Category image (optional)')
                ->image()
                ->disk('public')
                ->directory('blog-categories')
                ->imageEditor()
                ->nullable(),

            Select::make('parent_id')
                ->label('Parent category')
                ->relationship(
                    name: 'parent',
                    titleAttribute: 'name',
                    // Exclude self AND the whole subtree so a category can never be
                    // reparented under one of its own descendants (which would form
                    // a cycle in the hierarchy).
                    modifyQueryUsing: fn ($query, ?Category $record) => $record
                        ? $query->whereNotIn('id', [$record->getKey(), ...$record->descendantIds()])
                        : $query,
                )
                ->searchable()
                ->placeholder('— top level —')
                // Server-side cycle rejection with a clear message (the observer is
                // the last-resort safety net; this gives the user real feedback).
                ->rules([
                    fn (?Category $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ($record === null || blank($value)) {
                            return;
                        }

                        if (in_array((int) $value, [$record->getKey(), ...$record->descendantIds()], true)) {
                            $fail('A category cannot be its own parent or nested under one of its descendants.');
                        }
                    },
                ]),

            Textarea::make('description')
                ->rows(3)
                ->maxLength(1000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('parent.name')->label((string) __('blog::resources.category.columns.parent'))->default('—')->toggleable(),
                TextColumn::make('slug')->badge()->color('gray'),
                TextColumn::make('posts_count')->counts('posts')->label((string) __('blog::resources.category.columns.posts')),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCategories::route('/'),
        ];
    }
}
