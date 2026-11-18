<?php

namespace App\Domain\Import;

use App\Domain\Cleaning\CleaningPipeline;
use App\Enums\LeadVerdict;
use App\Models\BatchLead;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelWriter;

class ImportOutputWriter
{
    /**
     * @return array<string, string>
     */
    public function write(ImportBatch $batch): array
    {
        $dir = 'imports/'.$batch->id.'/outputs';
        Storage::disk('local')->makeDirectory($dir);

        $emailCol = (string) data_get($batch->column_mapping, 'email', 'email');

        $paths = [
            'final' => $dir.'/final_smartlead.csv',
            'gateway' => $dir.'/gateway.csv',
            'later' => $dir.'/later.csv',
            'report' => $dir.'/report.xlsx',
        ];

        $this->writeCsv($batch, LeadVerdict::Safe, $paths['final'], $emailCol);
        $this->writeCsv($batch, LeadVerdict::Gateway, $paths['gateway'], $emailCol);
        $this->writeCsv($batch, LeadVerdict::Later, $paths['later'], $emailCol);

        $report = SimpleExcelWriter::create(Storage::disk('local')->path($paths['report']));
        $report->nameCurrentSheet('Final');
        $this->streamSheet($report, $batch, LeadVerdict::Safe, $emailCol);
        $report->addNewSheetAndMakeItCurrent('Removed');
        $this->streamRemovedSheet($report, $batch, $emailCol);
        $report->addNewSheetAndMakeItCurrent('Gateway');
        $this->streamSheet($report, $batch, LeadVerdict::Gateway, $emailCol);
        $report->addNewSheetAndMakeItCurrent('Later');
        $this->streamSheet($report, $batch, LeadVerdict::Later, $emailCol);
        $report->addNewSheetAndMakeItCurrent('Summary');
        foreach (CleaningPipeline::reasonCodes() as $code) {
            $report->addRow([
                'rule' => $code,
                'count' => (int) data_get($batch->stats, 'reason_counts.'.$code, 0),
            ]);
        }
        $report->close();

        return $paths;
    }

    private function writeCsv(ImportBatch $batch, LeadVerdict $verdict, string $relativePath, string $emailCol): void
    {
        $writer = SimpleExcelWriter::create(Storage::disk('local')->path($relativePath));
        $this->streamSheet($writer, $batch, $verdict, $emailCol);
        $writer->close();
    }

    private function streamSheet(SimpleExcelWriter $writer, ImportBatch $batch, LeadVerdict $verdict, string $emailCol): void
    {
        $wrote = false;
        BatchLead::query()
            ->where('import_batch_id', $batch->id)
            ->where('verdict', $verdict->value)
            ->orderBy('row_number')
            ->chunkById(500, function ($chunk) use ($writer, $emailCol, &$wrote) {
                foreach ($chunk as $lead) {
                    $writer->addRow($this->rowFromLead($lead, $emailCol));
                    $wrote = true;
                }
            });

        if (! $wrote) {
            $writer->addRow(['cleaned_email' => '', 'verdict' => $verdict->value, 'reason' => '']);
        }
    }

    private function streamRemovedSheet(SimpleExcelWriter $writer, ImportBatch $batch, string $emailCol): void
    {
        $wrote = false;
        BatchLead::query()
            ->where('import_batch_id', $batch->id)
            ->where('verdict', LeadVerdict::Rejected->value)
            ->orderBy('row_number')
            ->chunkById(500, function ($chunk) use ($writer, $emailCol, &$wrote) {
                foreach ($chunk as $lead) {
                    $writer->addRow($this->rowFromLead($lead, $emailCol));
                    $wrote = true;
                }
            });

        if (! $wrote) {
            $writer->addRow(['cleaned_email' => '', 'verdict' => 'rejected', 'reason' => '']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFromLead(BatchLead $lead, string $emailCol): array
    {
        $raw = $lead->raw ?? [];
        $row = [];
        foreach ($raw as $key => $value) {
            if (! str_starts_with((string) $key, '_')) {
                $row[$key] = $value;
            }
        }
        $row['cleaned_email'] = strtolower(trim((string) ($raw[$emailCol] ?? '')));
        $row['verdict'] = $lead->verdict?->value;
        $row['reason'] = $lead->reasons[0]['message'] ?? '';
        $row['reason_code'] = $lead->reasons[0]['code'] ?? '';
        if ($lead->suggested_fix) {
            $row['suggested_fix'] = $lead->suggested_fix;
        }

        return $row;
    }
}
