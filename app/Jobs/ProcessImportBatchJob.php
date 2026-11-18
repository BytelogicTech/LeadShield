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

    /** PHP 8.4 can treat max_execution_time=0 as "0 seconds exceeded" — use a high finite limit. */
    public int $timeout = 86400;

    public int $tries = 1;

    public function __construct(public int $importBatchId) {}

    public function handle(ImportBatchProcessor $processor): void
    {
        // Do not use 0 here: on PHP 8.4 it can fatal with "0 seconds exceeded" during DNS lookups.
        set_time_limit(86400);
        ini_set('max_execution_time', '86400');

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
