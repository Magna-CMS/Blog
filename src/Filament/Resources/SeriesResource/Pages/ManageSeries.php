<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Resources\SeriesResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use MagnaCms\Blog\Filament\Resources\SeriesResource;

class ManageSeries extends ManageRecords
{
    protected static string $resource = SeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
