<?php

namespace App\Filament\Resources\ImportBatches\Widgets;

use App\Models\ImportBatch;
use App\Support\CleaningUi;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportBatchStatsOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    public ?ImportBatch $record = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected function getColumns(): int|array|null
    {
        return 5;
    }

    protected function getPollingInterval(): ?string
    {
        if (! $this->record) {
            return null;
        }

        return in_array($this->record->status, ['queued', 'processing'], true) ? '2s' : null;
    }

    public function getPollingIntervalForView(): ?string
    {
        return $this->getPollingInterval();
    }

    public function getPublicContent(): Schema
    {
        return $this->getSchema('content');
    }

    public function render(): View
    {
        $this->record?->refresh();

        if ($this->record && in_array($this->record->status, ['queued', 'processing'], true)) {
            return view('filament.import-batches.progress', [
                'record' => $this->record,
            ]);
        }

        return view('filament.import-batches.stats-with-downloads', [
            'widget' => $this,
            'record' => $this->record,
        ]);
    }

    protected function getStats(): array
    {
        $batch = $this->record;
        if (! $batch) {
            return [];
        }

        $clean = CleaningUi::finalCount($batch);
        $later = CleaningUi::laterCount($batch);
        $gateway = CleaningUi::gatewayCount($batch);
        $removed = CleaningUi::removedCount($batch);

        return [
            Stat::make('Total rows', number_format((int) $batch->total_rows)),
            Stat::make('Clean', CleaningUi::metricLabel($batch, $clean))->color('success'),
            Stat::make('Later', CleaningUi::metricLabel($batch, $later))->color('warning'),
            Stat::make('Gateway', CleaningUi::metricLabel($batch, $gateway))->color('purple'),
            Stat::make('Removed', CleaningUi::metricLabel($batch, $removed))->color('danger'),
        ];
    }

    public function downloadOutput(string $key, string $downloadName): StreamedResponse
    {
        $path = data_get($this->record?->stats, 'output_paths.'.$key);
        if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
            Notification::make()->title('File not ready')->danger()->send();
            abort(404);
        }

        return Storage::disk('local')->download($path, $downloadName);
    }
}
