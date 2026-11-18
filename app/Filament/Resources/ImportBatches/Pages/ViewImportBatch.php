<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\ImportBatches\Widgets\ImportBatchReasonBreakdown;
use App\Filament\Resources\ImportBatches\Widgets\ImportBatchStatsOverview;
use App\Models\ImportBatch;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

/**
 * @property-read ImportBatch $record
 */
class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderWidgets(): array
    {
        /** @var ImportBatch $record */
        $record = $this->getRecord();

        $widgets = [
            ImportBatchStatsOverview::class,
        ];

        if ($record->status === 'completed') {
            $widgets[] = ImportBatchReasonBreakdown::class;
        }

        return $widgets;
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            'record' => $this->getRecord(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getRelationManagersContentComponent(),
        ]);
    }

    /**
     * Keep the detail page live while cleaning runs (progress widget + status).
     */
    public function getExtraBodyAttributes(): array
    {
        /** @var ImportBatch $record */
        $record = $this->getRecord();

        if (! in_array($record->status, ['queued', 'processing'], true)) {
            return [];
        }

        return [
            'wire:poll.2s' => '',
        ];
    }

    public function getHeading(): string
    {
        /** @var ImportBatch $record */
        $record = $this->getRecord();

        return 'Upload #'.$record->id;
    }

    public function getSubheading(): ?string
    {
        /** @var ImportBatch $record */
        $record = $this->getRecord();

        if ($record->status === 'failed') {
            return (string) data_get($record->stats, 'error', 'Processing failed.');
        }

        return $record->original_filename;
    }
}
