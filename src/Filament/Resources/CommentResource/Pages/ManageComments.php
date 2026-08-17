<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Resources\CommentResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use MagnaCms\Blog\Filament\Resources\CommentResource;

class ManageComments extends ManageRecords
{
    protected static string $resource = CommentResource::class;

    // Comments are created through the delivery API, not the admin — no create action.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
