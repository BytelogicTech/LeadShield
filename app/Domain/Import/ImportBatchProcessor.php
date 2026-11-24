<?php

namespace App\Domain\Import;

use App\Domain\Cleaning\CleaningPipeline;
use App\Domain\Cleaning\LeadContext;
use App\Domain\Dns\DnsResolver;
use App\Domain\Suppression\SuppressionWriter;
use App\Enums\LeadVerdict;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\BatchLead;
use App\Models\ImportBatch;
use App\Support\CleaningUi;
use App\Support\SuppressionSources;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportBatchProcessor
{
    /**
     * Live cleaning suppressions (LeadShield source).
     * listclean_dirty is written by ListCleanStage with source=listclean — do not duplicate here.
     *
     * @var list<string>
     */
    private const SUPPRESS_ON_REJECT = [
        'role_address',
        'typo_domain',
        'disposable',
        'domain_nxdomain',
        'domain_no_mail',
        'domain_no_mx',
        'generated_address',
    ];

    public function __construct(
        private LeadFileReader $reader,
        private ImportOutputWriter $outputs,
        private ListCleanReader $listCleanReader,
        private SuppressionWriter $suppressions,
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

        $listCleanStatuses = null;
        if (filled($batch->listclean_path)) {
            $listCleanAbsolute = Storage::disk('local')->path((string) $batch->listclean_path);
            if (! is_file($listCleanAbsolute)) {
                throw new RuntimeException('ListClean file is missing.');
            }
            $listCleanStatuses = $this->listCleanReader->load($listCleanAbsolute);
        }

        $pipeline = new CleaningPipeline(
            (int) $batch->workspace_id,
            $dns,
            $listCleanStatuses,
            $batch->user_id ? (int) $batch->user_id : null,
        );
        $pipeline->resetBatchState();

        $headers = $this->reader->headers($absolute);
        $emailCol = data_get($batch->column_mapping, 'email');
        if (! filled($emailCol)) {
            $samples = $this->reader->sampleRows($absolute, 25);
            $emailCol = $this->reader->detectEmailColumn($headers, $samples);
        }
        $emailCol = (string) $emailCol;

        // Count first so the UI can show real x / y (pct%) instead of 0%.
        $totalRows = $this->reader->countRows($absolute);

        $batch->update([
            'status' => 'processing',
            'dns_status' => 'ok',
            'column_mapping' => array_merge($batch->column_mapping ?? [], ['email' => $emailCol]),
            'started_at' => $batch->started_at ?? now(),
            'total_rows' => $totalRows,
            'processed_rows' => 0,
        ]);

        BatchLead::query()->where('import_batch_id', $batch->id)->delete();

        $counts = array_fill_keys(CleaningPipeline::reasonCodes(), 0);
        $processed = 0;
        $chunk = [];
        /** @var list<array{value: string, reason: SuppressionReason, source: string, note: ?string}> $suppressionChunk */
        $suppressionChunk = [];

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

            if (
                $ctx->verdict === LeadVerdict::Rejected
                && $ctx->email !== ''
                && in_array($ctx->primaryCode(), self::SUPPRESS_ON_REJECT, true)
            ) {
                $suppressionChunk[] = [
                    'value' => $ctx->email,
                    'reason' => SuppressionReason::Cleaning,
                    'source' => SuppressionSources::LEADSHIELD,
                    'note' => 'Upload #'.$batch->id.': '.$ctx->primaryCode().' — '.$ctx->primaryReason(),
                ];
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

            if (count($chunk) >= 100) {
                BatchLead::query()->insert($chunk);
                $chunk = [];
                $this->flushSuppressions($batch, $suppressionChunk);
                $suppressionChunk = [];
                $this->touchProgress($batch, $processed, $counts);
            }
        }

        if ($chunk !== []) {
            BatchLead::query()->insert($chunk);
        }
        $this->flushSuppressions($batch, $suppressionChunk);

        $totals = CleaningUi::liveTotals($counts, $processed);

        $batch->update([
            'total_rows' => $processed,
            'processed_rows' => $processed,
            'stats' => [
                'reason_counts' => $counts,
                'totals' => $totals,
            ],
        ]);

        $paths = $this->outputs->write($batch->fresh());

        $batch->update([
            'status' => 'completed',
            'finished_at' => now(),
            'stats' => [
                'reason_counts' => $counts,
                'output_paths' => $paths,
                'listclean_used' => $listCleanStatuses !== null,
                'totals' => $totals,
            ],
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function touchProgress(ImportBatch $batch, int $processed, array $counts): void
    {
        $batch->update([
            'processed_rows' => $processed,
            'stats' => [
                'reason_counts' => $counts,
                'totals' => CleaningUi::liveTotals($counts, $processed),
            ],
        ]);
    }

    /**
     * @param  list<array{value: string, reason: SuppressionReason, source: string, note: ?string}>  $rows
     */
    private function flushSuppressions(ImportBatch $batch, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->suppressions->upsertMany(
            workspaceId: (int) $batch->workspace_id,
            type: SuppressionType::Email,
            rows: $rows,
            createdBy: $batch->user_id ? (int) $batch->user_id : null,
        );
    }
}
