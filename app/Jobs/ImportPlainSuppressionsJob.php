<?php

namespace App\Jobs;

use App\Domain\Suppression\PlainBlocklistImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportPlainSuppressionsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(
        public string $storagePath,
        public int $workspaceId,
        public ?int $userId = null,
    ) {}

    public function handle(PlainBlocklistImporter $importer): void
    {
        $absolute = Storage::disk('local')->path($this->storagePath);
        $stats = $importer->import($absolute, $this->workspaceId, $this->userId);
        Log::info('Plain suppression import finished', $stats + ['path' => $this->storagePath]);
        Storage::disk('local')->delete($this->storagePath);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Plain suppression import failed', [
            'path' => $this->storagePath,
            'error' => $e?->getMessage(),
        ]);
    }
}
