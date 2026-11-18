<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Domain\Cleaning\CleaningPipeline;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Models\ImportBatch;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @property-read ImportBatch $record
 */
class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    public function getPollingInterval(): ?string
    {
        /** @var ImportBatch $record */
        $record = $this->getRecord();

        return in_array($record->status, ['queued', 'processing'], true) ? '2s' : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->action(fn () => $this->refreshFormData(['status', 'processed_rows', 'total_rows', 'stats'])),
            Action::make('download_final')
                ->label('Download Final CSV')
                ->visible(fn () => $this->hasOutput('final'))
                ->action(fn () => $this->download('final', 'final_smartlead.csv')),
            Action::make('download_gateway')
                ->label('Download Gateway CSV')
                ->visible(fn () => $this->hasOutput('gateway'))
                ->action(fn () => $this->download('gateway', 'gateway.csv')),
            Action::make('download_later')
                ->label('Download Later CSV')
                ->visible(fn () => $this->hasOutput('later'))
                ->action(fn () => $this->download('later', 'later.csv')),
            Action::make('download_report')
                ->label('Download Excel report')
                ->visible(fn () => $this->hasOutput('report'))
                ->action(fn () => $this->download('report', 'report.xlsx')),
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

        if (in_array($record->status, ['queued', 'processing'], true)) {
            $pct = $record->total_rows > 0
                ? (int) floor(($record->processed_rows / max(1, $record->total_rows)) * 100)
                : 0;

            return "Cleaning in progress… {$record->processed_rows} rows ({$pct}%). Keep the queue worker running.";
        }

        if ($record->status === 'completed') {
            $parts = [];
            foreach (CleaningPipeline::reasonCodes() as $code) {
                $parts[] = $code.': '.(int) data_get($record->stats, 'reason_counts.'.$code, 0);
            }

            return implode(' | ', $parts);
        }

        return null;
    }

    private function hasOutput(string $key): bool
    {
        /** @var ImportBatch $record */
        $record = $this->getRecord();
        $path = data_get($record->stats, 'output_paths.'.$key);

        return is_string($path) && Storage::disk('local')->exists($path);
    }

    private function download(string $key, string $downloadName): StreamedResponse
    {
        /** @var ImportBatch $record */
        $record = $this->getRecord();
        $path = data_get($record->stats, 'output_paths.'.$key);
        if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
            Notification::make()->title('File not ready')->danger()->send();
            abort(404);
        }

        return Storage::disk('local')->download($path, $downloadName);
    }
}
