<?php

namespace App\Filament\Resources\Suppressions\Pages;

use App\Domain\Suppression\PlainBlocklistImporter;
use App\Domain\Suppression\SmartleadExportImporter;
use App\Filament\Resources\Suppressions\SuppressionResource;
use App\Models\Suppression;
use App\Support\SuppressionSources;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageSuppressions extends ManageRecords
{
    protected static string $resource = SuppressionResource::class;

    public function getHeading(): string
    {
        $total = Suppression::query()->count();

        return 'Suppressions ('.number_format($total).')';
    }

    public function getSubheading(): ?string
    {
        $counts = Suppression::query()
            ->selectRaw('source, COUNT(*) as aggregate')
            ->groupBy('source')
            ->pluck('aggregate', 'source');

        if ($counts->isEmpty()) {
            return 'No suppressions yet. Removals from cleaning, Smartlead, and ListClean appear here.';
        }

        $parts = [];
        foreach (SuppressionSources::options() as $key => $label) {
            $n = (int) ($counts[$key] ?? 0);
            if ($n > 0) {
                $parts[] = number_format($n).' '.$label;
            }
        }

        foreach ($counts as $source => $n) {
            if (! array_key_exists((string) $source, SuppressionSources::options())) {
                $parts[] = number_format((int) $n).' '.SuppressionSources::label((string) $source);
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add one')
                ->mutateFormDataUsing(function (array $data): array {
                    $value = strtolower(trim((string) ($data['value'] ?? '')));
                    $value = ltrim($value, '@');
                    $data['value'] = $value;
                    $data['value_hash'] = Suppression::hashValue($value);
                    $data['workspace_id'] = auth()->user()->workspace_id;
                    $data['created_by'] = auth()->id();
                    $data['permanent'] = $data['permanent'] ?? true;

                    return $data;
                }),
            Action::make('import_smartlead')
                ->label('Import Smartlead export')
                ->form([
                    FileUpload::make('file')
                        ->label('Smartlead campaign CSV/XLSX')
                        ->helperText('Only rows where Bounce / is_bounced is true are added to suppressions. Max 64 MB.')
                        ->disk('local')
                        ->directory('suppressions/tmp')
                        ->required()
                        ->maxSize(65536)
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            '.csv',
                            '.xlsx',
                        ]),
                ])
                ->action(function (array $data): void {
                    $path = $data['file'] ?? null;
                    if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
                        Notification::make()->title('Upload failed')->danger()->send();

                        return;
                    }

                    try {
                        set_time_limit(0);
                        $stats = app(SmartleadExportImporter::class)->import(
                            Storage::disk('local')->path($path),
                            (int) auth()->user()->workspace_id,
                            auth()->id(),
                        );
                        Storage::disk('local')->delete($path);

                        Notification::make()
                            ->title('Smartlead import finished')
                            ->body("Added {$stats['created']}, updated {$stats['updated']}, skipped {$stats['skipped']} (total rows {$stats['total']}).")
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Smartlead import failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('import_plain')
                ->label('Import emails / domains')
                ->form([
                    FileUpload::make('file')
                        ->label('CSV or TXT (emails, or @domain / domain lines)')
                        ->disk('local')
                        ->directory('suppressions/tmp')
                        ->required()
                        ->maxSize(65536)
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            '.csv',
                            '.xlsx',
                            '.txt',
                        ]),
                ])
                ->action(function (array $data): void {
                    $path = $data['file'] ?? null;
                    if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
                        Notification::make()->title('Upload failed')->danger()->send();

                        return;
                    }

                    try {
                        set_time_limit(0);
                        $stats = app(PlainBlocklistImporter::class)->import(
                            Storage::disk('local')->path($path),
                            (int) auth()->user()->workspace_id,
                            auth()->id(),
                        );
                        Storage::disk('local')->delete($path);

                        Notification::make()
                            ->title('Blocklist import finished')
                            ->body("Added {$stats['created']}, updated {$stats['updated']}, skipped {$stats['skipped']} (total rows {$stats['total']}).")
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Blocklist import failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('export')
                ->label('Export CSV')
                ->action(fn () => $this->exportCsv()),
            Action::make('clear_all')
                ->label('Clear all')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Clear all suppressions?')
                ->modalDescription(fn (): string => 'Permanently delete all '.number_format(Suppression::query()->count()).' suppressions in this workspace. This cannot be undone.')
                ->modalSubmitActionLabel('Yes, delete all')
                ->visible(fn (): bool => Suppression::query()->exists())
                ->action(function (): void {
                    set_time_limit(0);
                    $deleted = 0;
                    Suppression::query()
                        ->orderBy('id')
                        ->chunkById(1000, function ($rows) use (&$deleted): void {
                            $ids = $rows->pluck('id')->all();
                            $deleted += Suppression::query()->whereIn('id', $ids)->forceDelete();
                        });

                    Notification::make()
                        ->title('Suppressions cleared')
                        ->body(number_format($deleted).' records deleted.')
                        ->success()
                        ->send();

                    $this->resetTable();
                }),
        ];
    }

    private function exportCsv(): StreamedResponse
    {
        set_time_limit(0);
        $filename = 'suppressions_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['type', 'value', 'reason', 'bounce_detail', 'source', 'permanent', 'note', 'created_at']);

            Suppression::query()
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($out): void {
                    foreach ($rows as $row) {
                        /** @var Suppression $row */
                        fputcsv($out, [
                            $row->type->value,
                            (string) $row->value,
                            $row->reason->value,
                            $row->bounce_detail?->value,
                            (string) $row->source,
                            $row->permanent ? '1' : '0',
                            (string) $row->note,
                            $row->created_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
