<?php

namespace App\Jobs;

use App\Domain\Import\ImportBatchProcessor;
use App\Models\ImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessImportBatchJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(public int $importBatchId) {}

    public function handle(ImportBatchProcessor $processor): void
    {
        $batch = ImportBatch::query()->withoutGlobalScopes()->findOrFail($this->importBatchId);
        $processor->process($batch);
    }

    public function failed(?Throwable $exception): void
    {
        $batch = ImportBatch::query()->withoutGlobalScopes()->find($this->importBatchId);
        if (! $batch) {
            return;
        }

        $message = $exception?->getMessage() ?: 'Import failed.';
        Log::error('Import batch failed', ['id' => $this->importBatchId, 'error' => $message]);

        $batch->update([
            'status' => 'failed',
            'finished_at' => now(),
            'stats' => array_merge($batch->stats ?? [], ['error' => $message]),
        ]);
    }
}
