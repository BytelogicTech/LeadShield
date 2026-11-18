<?php

namespace App\Console\Commands;

use App\Models\BatchLead;
use App\Models\ImportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredImportData extends Command
{
    protected $signature = 'leadshield:purge-imports {--days= : Override retention days}';

    protected $description = 'Delete uploaded files and import outputs older than the retention window. Suppressions and DNS cache are never purged.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('leadshield.retention.purge_files_after_days', 7));
        $cutoff = now()->subDays($days);

        $batches = ImportBatch::query()
            ->withoutGlobalScopes()
            ->where('created_at', '<', $cutoff)
            ->get();

        $purged = 0;

        foreach ($batches as $batch) {
            BatchLead::query()->where('import_batch_id', $batch->id)->delete();

            if ($batch->storage_path && Storage::disk('local')->exists($batch->storage_path)) {
                Storage::disk('local')->delete($batch->storage_path);
            }

            $outputs = data_get($batch->stats, 'output_paths', []);
            if (is_array($outputs)) {
                foreach ($outputs as $path) {
                    if (is_string($path) && Storage::disk('local')->exists($path)) {
                        Storage::disk('local')->delete($path);
                    }
                }
            }

            $batch->delete();
            $purged++;
        }

        $this->info("Purged {$purged} import batch(es) older than {$days} day(s). Suppressions and DNS cache untouched.");

        return self::SUCCESS;
    }
}
