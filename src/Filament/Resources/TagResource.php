<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use MagnaCms\Blog\Filament\Resources\TagResource\Pages;
use MagnaCms\Blog\Models\Tag;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return (string) __('blog::resources.group');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('blog::resources.tag.navigation');
    }

    public static function getModelLabel(): string
    {
        return (string) __('blog::resources.tag.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('blog::resources.tag.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('blog.tags.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('blog.tags.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('blog.tags.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('blog.tags.manage') ?? false;
    }

    /**
     * A WordPress-style hover submenu: All Tags / New Tag.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (! (auth()->user()?->can('blog.tags.manage') ?? false)) {
            return [];
        }

        $base = static::getRouteBaseName();

        return [
            NavigationItem::make((string) __('blog::resources.tag.navigation'))
                ->group((string) __('blog::resources.group'))
                ->icon('heroicon-o-tag')
                ->sort(3)
                ->url('')
                ->childItems([
                    NavigationItem::make((string) __('blog::resources.tag.nav.all'))
                        ->url(static::getUrl('index'))
                        ->icon('heroicon-m-list-bullet')
                        ->isActiveWhen(fn (): bool => request()->routeIs($base.'.index') && request()->query('action') !== 'create'),

                    NavigationItem::make((string) __('blog::resources.tag.nav.new'))
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
                ->unique(table: 'blog_tags', column: 'slug', ignoreRecord: true)
                ->helperText('Auto-filled from the name; edit if needed.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->badge()->color('gray'),
                TextColumn::make('posts_count')->counts('posts')->label((string) __('blog::resources.tag.columns.posts')),
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
            'index' => Pages\ManageTags::route('/'),
        ];
    }
}
