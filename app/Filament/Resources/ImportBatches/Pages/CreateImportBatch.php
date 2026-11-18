<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Jobs\ProcessImportBatchJob;
use App\Models\ImportBatch;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateImportBatch extends CreateRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function handleRecordCreation(array $data): ImportBatch
    {
        $path = $data['upload'] ?? null;
        $original = $data['original_filename'] ?? basename((string) $path);
        $listclean = $data['listclean'] ?? null;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new \RuntimeException('Upload failed. Please try again.');
        }

        if (filled($listclean) && ! Storage::disk('local')->exists((string) $listclean)) {
            throw new \RuntimeException('ListClean upload failed. Please try again.');
        }

        $batch = ImportBatch::query()->create([
            'workspace_id' => auth()->user()->workspace_id,
            'user_id' => auth()->id(),
            'lead_source_id' => $data['lead_source_id'] ?? null,
            'original_filename' => is_array($original) ? (string) reset($original) : (string) $original,
            'storage_path' => $path,
            'listclean_path' => filled($listclean) ? (string) $listclean : null,
            'column_mapping' => filled($data['email_column'] ?? null)
                ? ['email' => $data['email_column']]
                : null,
            'status' => 'queued',
            'total_rows' => 0,
            'processed_rows' => 0,
        ]);

        ProcessImportBatchJob::dispatch($batch->id);

        return $batch;
    }

    protected function getRedirectUrl(): string
    {
        return ImportBatchResource::getUrl('view', ['record' => $this->record]);
    }
}
