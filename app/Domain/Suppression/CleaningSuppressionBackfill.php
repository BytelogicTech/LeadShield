<?php

namespace App\Domain\Suppression;

use App\Enums\LeadVerdict;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\BatchLead;
use App\Models\ImportBatch;
use App\Support\CleaningUi;
use App\Support\SuppressionSources;

class CleaningSuppressionBackfill
{
    /** Reject codes that should become permanent LeadShield suppressions. */
    public const SUPPRESS_CODES = [
        'role_address',
        'typo_domain',
        'disposable',
        'domain_nxdomain',
        'domain_no_mail',
        'domain_no_mx',
        'generated_address',
        'listclean_dirty',
    ];

    public function __construct(private SuppressionWriter $writer) {}

    /**
     * @return array{created: int, updated: int, skipped: int, scanned: int}
     */
    public function fromBatch(ImportBatch $batch): array
    {
        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'scanned' => 0];

        BatchLead::query()
            ->where('import_batch_id', $batch->id)
            ->where('verdict', LeadVerdict::Rejected->value)
            ->orderBy('id')
            ->chunkById(200, function ($leads) use ($batch, &$totals): void {
                $rows = [];

                foreach ($leads as $lead) {
                    $totals['scanned']++;
                    $raw = is_array($lead->raw) ? $lead->raw : null;
                    $email = CleaningUi::emailFromRaw($raw);
                    $code = (string) data_get($lead->reasons, '0.code', '');

                    if ($email === '' || ! in_array($code, self::SUPPRESS_CODES, true)) {
                        $totals['skipped']++;

                        continue;
                    }

                    // ListClean dirty already stored as source=listclean during cleaning.
                    $source = $code === 'listclean_dirty'
                        ? SuppressionSources::LISTCLEAN
                        : SuppressionSources::LEADSHIELD;
                    $reason = $code === 'listclean_dirty'
                        ? SuppressionReason::Dirty
                        : SuppressionReason::Cleaning;

                    $rows[] = [
                        'value' => $email,
                        'reason' => $reason,
                        'source' => $source,
                        'note' => 'Upload #'.$batch->id.': '.$code,
                    ];
                }

                if ($rows === []) {
                    return;
                }

                $result = $this->writer->upsertMany(
                    workspaceId: (int) $batch->workspace_id,
                    type: SuppressionType::Email,
                    rows: $rows,
                    createdBy: $batch->user_id ? (int) $batch->user_id : null,
                );
                $totals['created'] += $result['created'];
                $totals['updated'] += $result['updated'];
                $totals['skipped'] += $result['skipped'];
            });

        return $totals;
    }

    /**
     * @return array{created: int, updated: int, skipped: int, scanned: int, batches: int}
     */
    public function fromAllCompletedBatches(): array
    {
        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'scanned' => 0, 'batches' => 0];

        ImportBatch::query()
            ->withoutGlobalScopes()
            ->where('status', 'completed')
            ->orderBy('id')
            ->each(function (ImportBatch $batch) use (&$totals): void {
                $totals['batches']++;
                $result = $this->fromBatch($batch);
                $totals['created'] += $result['created'];
                $totals['updated'] += $result['updated'];
                $totals['skipped'] += $result['skipped'];
                $totals['scanned'] += $result['scanned'];
            });

        return $totals;
    }
}
