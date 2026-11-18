<?php

namespace App\Filament\Resources\Suppressions\Pages;

use App\Filament\Resources\Suppressions\SuppressionResource;
use App\Jobs\ImportPlainSuppressionsJob;
use App\Jobs\ImportSmartleadSuppressionsJob;
use App\Models\Suppression;
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
                        ->disk('local')
                        ->directory('suppressions/tmp')
                        ->required()
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

                    ImportSmartleadSuppressionsJob::dispatch(
                        $path,
                        (int) auth()->user()->workspace_id,
                        auth()->id(),
                    );

                    Notification::make()
                        ->title('Smartlead import queued')
                        ->body('Keep ./bin/queue.sh running. Every email in the file will be added to the suppression list.')
                        ->success()
                        ->send();
                }),
            Action::make('import_plain')
                ->label('Import emails / domains')
                ->form([
                    FileUpload::make('file')
                        ->label('CSV or TXT (emails, or @domain / domain lines)')
                        ->disk('local')
                        ->directory('suppressions/tmp')
                        ->required()
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

                    ImportPlainSuppressionsJob::dispatch(
                        $path,
                        (int) auth()->user()->workspace_id,
                        auth()->id(),
                    );

                    Notification::make()
                        ->title('Blocklist import queued')
                        ->body('Keep ./bin/queue.sh running.')
                        ->success()
                        ->send();
                }),
            Action::make('export')
                ->label('Export CSV')
                ->action(fn () => $this->exportCsv()),
        ];
    }

    private function exportCsv(): StreamedResponse
    {
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
                            $row->type instanceof \BackedEnum ? $row->type->value : (string) $row->type,
                            (string) $row->value,
                            $row->reason instanceof \BackedEnum ? $row->reason->value : (string) $row->reason,
                            $row->bounce_detail instanceof \BackedEnum ? $row->bounce_detail->value : (string) $row->bounce_detail,
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
