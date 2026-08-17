<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Resources;

use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;
use MagnaCms\Blog\Filament\Resources\PostResource\Pages;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\PostAccess;
use MagnaCms\Blog\Support\PostCsvExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return (string) __('blog::resources.group');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('blog::resources.post.navigation');
    }

    public static function getModelLabel(): string
    {
        return (string) __('blog::resources.post.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('blog::resources.post.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('blog.posts.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('blog.posts.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Post && PostAccess::canEdit(auth()->user(), $record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Post && PostAccess::canDelete(auth()->user(), $record);
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->can('blog.posts.delete') ?? false;
    }

    public static function canRestore(Model $record): bool
    {
        return $record instanceof Post && PostAccess::canRestore(auth()->user(), $record);
    }

    public static function canForceDelete(Model $record): bool
    {
        return $record instanceof Post && PostAccess::canDelete(auth()->user(), $record);
    }

    /**
     * A WordPress-style hover submenu: All Posts / Create post / Drafts (with a
     * live count badge).
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (! (auth()->user()?->can('blog.posts.view') ?? false)) {
            return [];
        }

        $draftCount = Post::query()->where('status', PostStatus::Draft->value)->count();
        $base = static::getRouteBaseName();

        return [
            NavigationItem::make((string) __('blog::resources.post.navigation'))
                ->group((string) __('blog::resources.group'))
                ->icon('heroicon-o-newspaper')
                ->sort(1)
                ->url('')
                ->childItems([
                    NavigationItem::make((string) __('blog::resources.post.nav.all'))
                        ->url(static::getUrl('index'))
                        ->icon('heroicon-m-list-bullet')
                        ->isActiveWhen(fn (): bool => request()->routeIs($base.'.index')),

                    NavigationItem::make((string) __('blog::resources.post.nav.create'))
                        ->url(static::getUrl('create'))
                        ->icon('heroicon-m-plus-circle')
                        ->isActiveWhen(fn (): bool => request()->routeIs($base.'.create') || request()->routeIs($base.'.edit')),

                    NavigationItem::make((string) __('blog::resources.post.nav.drafts'))
                        ->url(static::getUrl('index').'?tableFilters[status][value]=draft')
                        ->icon('heroicon-m-pencil-square')
                        ->badge($draftCount > 0 ? (string) $draftCount : null, color: 'warning')
                        ->isActiveWhen(fn (): bool => false),
                ]),
        ];
    }

    /** Eager-load relations rendered in the table to avoid N+1 queries. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['category', 'author'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    // The create/edit pages render a bespoke full-screen builder and define
    // their own canvas + sidebar schemas, so the resource itself needs no form.
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Authors (no edit-others) only ever list their own posts; editors and
            // admins (edit-others / super admin) see everyone's. Record resolution
            // for the edit page stays unscoped so an unauthorised direct URL gets a
            // clean 403 from canEdit() rather than a 404.
            ->modifyQueryUsing(function (Builder $query): void {
                $user = auth()->user();
                if ($user !== null && ! PostAccess::canManageOthers($user)) {
                    $query->where('author_id', $user->getAuthIdentifier());
                }
            })
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label((string) __('blog::resources.post.columns.category'))
                    ->default('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PostStatus $state): string => $state->label())
                    ->color(fn (PostStatus $state): string => match ($state) {
                        PostStatus::Published => 'success',
                        PostStatus::Draft => 'warning',
                        PostStatus::PendingReview => 'gray',
                        PostStatus::Scheduled => 'info',
                        PostStatus::Archived => 'danger',
                    }),

                TextColumn::make('visibility')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (PostVisibility $state): string => $state->label())
                    ->toggleable(),

                TextColumn::make('author.name')
                    ->label((string) __('blog::resources.post.columns.author'))
                    ->default('—')
                    ->toggleable(),

                ToggleColumn::make('is_featured')
                    ->label((string) __('blog::resources.post.columns.featured'))
                    ->sortable()
                    ->toggleable()
                    // Featuring surfaces a post through the delivery API, so it is a
                    // publish-tier act — the same gate the builder enforces. Editable
                    // columns run no record policy of their own (they bypass the
                    // model policy), and Filament refuses a state update on a disabled
                    // column server-side, so this closure is the authoritative guard
                    // against a forged toggle from a non-publisher.
                    ->disabled(fn (Post $record): bool => ! PostAccess::canPublish(auth()->user(), $record)),

                TextColumn::make('views')
                    ->label((string) __('blog::resources.post.columns.views'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(PostStatus::options()),
                SelectFilter::make('visibility')->options(PostVisibility::options()),
                SelectFilter::make('category_id')
                    ->label((string) __('blog::resources.post.columns.category'))
                    ->relationship('category', 'name'),
                TernaryFilter::make('is_featured')
                    ->label((string) __('blog::resources.post.columns.featured')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('exportCsv')
                        ->label((string) __('blog::resources.post.actions.export_csv'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): StreamedResponse {
                            // The table query already eager-loads category + author.
                            /** @var Collection<int, Post> $records */
                            $csv = app(PostCsvExporter::class)->toCsv($records);
                            $filename = 'blog-posts-'.now()->format('Y-m-d-His').'.csv';

                            return response()->streamDownload(
                                fn () => print ($csv),
                                $filename,
                                ['Content-Type' => 'text/csv'],
                            );
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
