<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Models\ImportBatch;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportBatches extends ListRecords
{
    protected static string $resource = ImportBatchResource::class;

    public function getHeading(): string
    {
        $total = static::getResource()::getEloquentQuery()->count();

        return 'Uploads ('.number_format($total).')';
    }

    public function getSubheading(): ?string
    {
        $busy = ImportBatch::query()
            ->whereIn('status', ['queued', 'processing'])
            ->count();

        if ($busy === 0) {
            return null;
        }

        return $busy === 1
            ? '1 upload cleaning in the background…'
            : "{$busy} uploads cleaning in the background…";
    }

    public function getPollingInterval(): ?string
    {
        $busy = ImportBatch::query()
            ->whereIn('status', ['queued', 'processing'])
            ->exists();

        return $busy ? '2s' : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Upload leads'),
        ];
    }
}
