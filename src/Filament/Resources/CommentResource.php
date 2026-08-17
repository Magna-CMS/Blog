<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use MagnaCms\Blog\Enums\CommentStatus;
use MagnaCms\Blog\Filament\Resources\CommentResource\Pages;
use MagnaCms\Blog\Models\Comment;

class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return (string) __('blog::resources.group');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('blog::resources.comment.navigation');
    }

    public static function getModelLabel(): string
    {
        return (string) __('blog::resources.comment.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('blog::resources.comment.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('blog.comments.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('blog.comments.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('blog.comments.manage') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! (auth()->user()?->can('blog.comments.manage') ?? false)) {
            return null;
        }

        $pending = Comment::query()->where('status', CommentStatus::Pending->value)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['post', 'author']);
    }

    /**
     * A WordPress-style hover submenu: All Comments / Pending / Spam. Comments
     * are never authored from the admin, so there is no "New" item; instead the
     * submenu jumps straight to the moderation queues.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (! (auth()->user()?->can('blog.comments.manage') ?? false)) {
            return [];
        }

        $base = static::getRouteBaseName();
        $pending = Comment::query()->where('status', CommentStatus::Pending->value)->count();

        $statusFilter = fn (string $status): string => static::getUrl('index')
            .'?tableFilters[status][value]='.$status;

        $statusIsActive = fn (string $status): bool => request()->routeIs($base.'.index')
            && request()->input('tableFilters.status.value') === $status;

        return [
            NavigationItem::make((string) __('blog::resources.comment.navigation'))
                ->group((string) __('blog::resources.group'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->sort(5)
                ->badge($pending > 0 ? (string) $pending : null, color: 'warning')
                ->url('')
                ->childItems([
                    NavigationItem::make((string) __('blog::resources.comment.nav.all'))
                        ->url(static::getUrl('index'))
                        ->icon('heroicon-m-list-bullet')
                        ->isActiveWhen(fn (): bool => request()->routeIs($base.'.index') && blank(request()->input('tableFilters.status.value'))),

                    NavigationItem::make((string) __('blog::resources.comment.nav.pending'))
                        ->url($statusFilter('pending'))
                        ->icon('heroicon-m-clock')
                        ->badge($pending > 0 ? (string) $pending : null, color: 'warning')
                        ->isActiveWhen(fn (): bool => $statusIsActive('pending')),

                    NavigationItem::make((string) __('blog::resources.comment.nav.spam'))
                        ->url($statusFilter('spam'))
                        ->icon('heroicon-m-shield-exclamation')
                        ->isActiveWhen(fn (): bool => $statusIsActive('spam')),
                ]),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')->required()->rows(4),
            Select::make('status')->options(CommentStatus::options())->required()->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('post.title')->label((string) __('blog::resources.comment.columns.post'))->limit(30)->toggleable(),
                TextColumn::make('author')
                    ->label((string) __('blog::resources.comment.columns.author'))
                    ->state(fn (Comment $record): string => (string) (data_get($record, 'author.name') ?? $record->author_name ?? 'Guest')),
                TextColumn::make('body')->limit(60)->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CommentStatus $state): string => $state->label())
                    ->color(fn (CommentStatus $state): string => match ($state) {
                        CommentStatus::Approved => 'success',
                        CommentStatus::Pending => 'warning',
                        CommentStatus::Spam => 'danger',
                    }),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(CommentStatus::options()),
                SelectFilter::make('post_id')->label((string) __('blog::resources.comment.columns.post'))->relationship('post', 'title'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label((string) __('blog::resources.comment.actions.approve'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Comment $record): bool => $record->status !== CommentStatus::Approved)
                    ->action(fn (Comment $record) => $record->update(['status' => CommentStatus::Approved->value])),
                Action::make('spam')
                    ->label((string) __('blog::resources.comment.actions.spam'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Comment $record): bool => $record->status !== CommentStatus::Spam)
                    ->action(fn (Comment $record) => $record->update(['status' => CommentStatus::Spam->value])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approveSelected')
                        ->label((string) __('blog::resources.comment.actions.approve'))
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->action(fn (Collection $records) => $records->each->update(['status' => CommentStatus::Approved->value])),
                    BulkAction::make('spamSelected')
                        ->label((string) __('blog::resources.comment.actions.spam'))
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->action(fn (Collection $records) => $records->each->update(['status' => CommentStatus::Spam->value])),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageComments::route('/'),
        ];
    }
}
