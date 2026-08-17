<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use MagnaCms\Blog\Filament\Resources\SeriesResource\Pages;
use MagnaCms\Blog\Models\Series;

class SeriesResource extends Resource
{
    protected static ?string $model = Series::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return (string) __('blog::resources.group');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('blog::resources.series.navigation');
    }

    public static function getModelLabel(): string
    {
        return (string) __('blog::resources.series.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('blog::resources.series.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('blog.series.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('blog.series.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('blog.series.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('blog.series.manage') ?? false;
    }

    /**
     * A WordPress-style hover submenu: All Series / New Series.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (! (auth()->user()?->can('blog.series.manage') ?? false)) {
            return [];
        }

        $base = static::getRouteBaseName();

        return [
            NavigationItem::make((string) __('blog::resources.series.navigation'))
                ->group((string) __('blog::resources.group'))
                ->icon('heroicon-o-queue-list')
                ->sort(4)
                ->url('')
                ->childItems([
                    NavigationItem::make((string) __('blog::resources.series.nav.all'))
                        ->url(static::getUrl('index'))
                        ->icon('heroicon-m-list-bullet')
                        ->isActiveWhen(fn (): bool => request()->routeIs($base.'.index') && request()->query('action') !== 'create'),

                    NavigationItem::make((string) __('blog::resources.series.nav.new'))
                        ->url(static::getUrl('index').'?action=create')
                        ->icon('heroicon-m-plus-circle')
                        ->isActiveWhen(fn (): bool => request()->routeIs($base.'.index') && request()->query('action') === 'create'),
                ]),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->unique(table: 'blog_series', column: 'slug', ignoreRecord: true)
                ->helperText('Auto-filled from the title; edit if needed.'),

            Textarea::make('description')
                ->rows(3)
                ->maxLength(1000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('slug')->badge()->color('gray'),
                TextColumn::make('posts_count')->counts('posts')->label((string) __('blog::resources.series.columns.parts')),
            ])
            ->defaultSort('title')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSeries::route('/'),
        ];
    }
}
