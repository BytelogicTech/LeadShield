<?php

namespace App\Domain\Import;

use App\Domain\Cleaning\CleaningPipeline;
use App\Domain\Cleaning\LeadContext;
use App\Domain\Dns\DnsResolver;
use App\Enums\LeadVerdict;
use App\Models\BatchLead;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportBatchProcessor
{
    public function __construct(
        private LeadFileReader $reader,
        private ImportOutputWriter $outputs,
    ) {}

    public function process(ImportBatch $batch): void
    {
        $batch->refresh();
        $absolute = Storage::disk('local')->path($batch->storage_path);

        if (! is_file($absolute)) {
            throw new RuntimeException('Uploaded file is missing.');
        }

        $dns = new DnsResolver;
        try {
            $dns->assertDnsWorks();
        } catch (RuntimeException $e) {
            $batch->update([
                'status' => 'failed',
                'dns_status' => 'canary_failed',
                'stats' => array_merge($batch->stats ?? [], ['error' => $e->getMessage()]),
                'finished_at' => now(),
            ]);

            throw $e;
        }

        $pipeline = new CleaningPipeline((int) $batch->workspace_id, $dns);
        $pipeline->resetBatchState();

        $headers = $this->reader->headers($absolute);
        $emailCol = data_get($batch->column_mapping, 'email')
            ?: $this->reader->detectEmailColumn($headers);

        $batch->update([
            'status' => 'processing',
            'dns_status' => 'ok',
            'column_mapping' => array_merge($batch->column_mapping ?? [], ['email' => $emailCol]),
            'started_at' => $batch->started_at ?? now(),
            'processed_rows' => 0,
        ]);

        BatchLead::query()->where('import_batch_id', $batch->id)->delete();

        $counts = array_fill_keys(CleaningPipeline::reasonCodes(), 0);
        $processed = 0;
        $chunk = [];

        [, $rows] = $this->reader->stream($absolute);

        foreach ($rows as $rowNumber => $row) {
            $row['_email_col'] = $emailCol;
            $ctx = $pipeline->process(new LeadContext($row, (int) $rowNumber));

            if ($ctx->verdict === LeadVerdict::Safe) {
                $counts['safe']++;
            } else {
                $code = $ctx->primaryCode() ?: 'unknown';
                if (! array_key_exists($code, $counts)) {
                    $counts[$code] = 0;
                }
                $counts[$code]++;
            }

            $chunk[] = [
                'import_batch_id' => $batch->id,
                'lead_id' => null,
                'row_number' => (int) $rowNumber,
                'raw' => json_encode($row, JSON_THROW_ON_ERROR),
                'verdict' => $ctx->verdict->value,
                'risk_score' => 0,
                'reasons' => json_encode($ctx->reasons, JSON_THROW_ON_ERROR),
                'suggested_fix' => $ctx->suggestedFix,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $processed++;

            if (count($chunk) >= 200) {
                BatchLead::query()->insert($chunk);
                $chunk = [];
                $batch->update(['processed_rows' => $processed, 'stats' => ['reason_counts' => $counts]]);
            }
        }

        if ($chunk !== []) {
            BatchLead::query()->insert($chunk);
        }

        $batch->update([
            'total_rows' => $processed,
            'processed_rows' => $processed,
            'stats' => ['reason_counts' => $counts],
        ]);

        $paths = $this->outputs->write($batch->fresh());

        $batch->update([
            'status' => 'completed',
            'finished_at' => now(),
            'stats' => [
                'reason_counts' => $counts,
                'output_paths' => $paths,
                'totals' => [
                    'safe' => $counts['safe'] ?? 0,
                    'gateway' => $counts['gateway'] ?? 0,
                    'later' => $counts['later_company_cap'] ?? 0,
                    'rejected' => max(0, $processed - ($counts['safe'] ?? 0) - ($counts['gateway'] ?? 0) - ($counts['later_company_cap'] ?? 0)),
                ],
            ],
        ]);
    }
}
