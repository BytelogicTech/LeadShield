<?php

namespace App\Filament\Resources\ImportBatches;

use App\Domain\Import\LeadFileReader;
use App\Filament\Resources\ImportBatches\Pages\CreateImportBatch;
use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Filament\Resources\ImportBatches\RelationManagers\BatchLeadsRelationManager;
use App\Filament\Resources\ImportBatches\Widgets\ImportBatchReasonBreakdown;
use App\Filament\Resources\ImportBatches\Widgets\ImportBatchStatsOverview;
use App\Models\ImportBatch;
use App\Support\CleaningUi;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static ?string $navigationLabel = 'Uploads';

    protected static ?string $modelLabel = 'Upload';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|\UnitEnum|null $navigationGroup = 'Data';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('upload')
                    ->label('Lead file (CSV or XLSX)')
                    ->disk('local')
                    ->directory('imports/tmp')
                    ->maxSize(65536)
                    ->acceptedFileTypes([
                        'text/csv',
                        'text/plain',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        '.csv',
                        '.xlsx',
                        '.txt',
                    ])
                    ->required()
                    ->storeFileNamesIn('original_filename')
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $set('email_column', self::recommendedEmailColumn($state));
                    }),
                Select::make('email_column')
                    ->label('Which column has the email address?')
                    ->helperText('Suggested from your file headers. Change this if the wrong column is selected.')
                    ->options(fn (Get $get): array => self::emailColumnOptions($get('upload')))
                    ->placeholder('Select email column…')
                    ->searchable()
                    ->required(fn (Get $get): bool => filled($get('upload')))
                    ->visible(fn (Get $get): bool => filled($get('upload')))
                    ->native(false)
                    ->live(),
                FileUpload::make('listclean')
                    ->label('ListClean result (optional)')
                    ->helperText('If provided, only emails marked clean are kept. Dirty emails are removed and saved under Suppressions → ListClean.')
                    ->disk('local')
                    ->directory('imports/tmp')
                    ->maxSize(65536)
                    ->acceptedFileTypes([
                        'text/csv',
                        'text/plain',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        '.csv',
                        '.xlsx',
                    ]),
                Select::make('lead_source_id')
                    ->label('Lead source (optional)')
                    ->relationship('leadSource', 'name')
                    ->searchable()
                    ->preload(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll(function (): ?string {
                return ImportBatch::query()
                    ->whereIn('status', ['queued', 'processing'])
                    ->exists() ? '2s' : null;
            })
            ->columns([
                TextColumn::make('id')
                    ->label('Upload #')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '#'.$state),
                TextColumn::make('original_filename')
                    ->label('File name')
                    ->searchable()
                    ->wrap()
                    ->limit(48),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CleaningUi::statusLabel($state))
                    ->color(fn (string $state): string => CleaningUi::statusColor($state)),
                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(function (ImportBatch $record): string {
                        if (in_array($record->status, ['queued', 'processing'], true)) {
                            $done = (int) $record->processed_rows;
                            $total = (int) $record->total_rows;
                            if ($total > 0) {
                                $pct = (int) min(100, floor(($done / max(1, $total)) * 100));

                                return number_format($done).' / '.number_format($total).' ('.$pct.'%)';
                            }

                            return $record->status === 'queued' ? 'Waiting in queue…' : 'Counting rows…';
                        }

                        if ($record->status === 'completed') {
                            return number_format((int) $record->total_rows).' rows';
                        }

                        if ($record->status === 'failed') {
                            return 'Failed';
                        }

                        return '—';
                    })
                    ->badge()
                    ->color(fn (ImportBatch $record): string => match ($record->status) {
                        'queued' => 'gray',
                        'processing' => 'info',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total_rows')
                    ->label('Total rows')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('final_count')
                    ->label('Clean')
                    ->state(fn (ImportBatch $record): int => CleaningUi::finalCount($record))
                    ->badge()
                    ->color('success')
                    ->alignEnd(),
                TextColumn::make('later_count')
                    ->label('Later')
                    ->state(fn (ImportBatch $record): int => CleaningUi::laterCount($record))
                    ->badge()
                    ->color('warning')
                    ->alignEnd(),
                TextColumn::make('gateway_count')
                    ->label('Gateway')
                    ->state(fn (ImportBatch $record): int => CleaningUi::gatewayCount($record))
                    ->badge()
                    ->color('purple')
                    ->alignEnd(),
                TextColumn::make('removed_count')
                    ->label('Removed')
                    ->state(fn (ImportBatch $record): int => CleaningUi::removedCount($record))
                    ->badge()
                    ->color('danger')
                    ->alignEnd(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make()->label('View detail'),
                DeleteAction::make()
                    ->label('Delete')
                    ->requiresConfirmation()
                    ->modalHeading('Delete this upload?')
                    ->modalDescription('Removes the upload, its cleaned files, and row details. Suppressions already saved from this upload stay in Suppressions.')
                    ->successNotificationTitle('Upload deleted'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected uploads?')
                        ->modalDescription('This cannot be undone. Suppressions already saved stay in Suppressions.'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            BatchLeadsRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [
            ImportBatchStatsOverview::class,
            ImportBatchReasonBreakdown::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportBatches::route('/'),
            'create' => CreateImportBatch::route('/create'),
            'view' => ViewImportBatch::route('/{record}'),
        ];
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function emailColumnOptions(mixed $uploadState): array
    {
        $absolute = self::resolveUploadAbsolutePath($uploadState);
        if ($absolute === null) {
            return [];
        }

        try {
            $reader = app(LeadFileReader::class);
            $headers = $reader->headers($absolute);
            $samples = $reader->sampleRows($absolute, 25);
            $candidates = $reader->detectEmailColumns($headers, $samples);

            $options = [];
            foreach ($candidates as $i => $column) {
                $options[$column] = $i === 0 ? "{$column} (recommended)" : $column;
            }

            foreach ($headers as $header) {
                $name = (string) $header;
                if ($name !== '' && ! array_key_exists($name, $options)) {
                    $options[$name] = $name;
                }
            }

            return $options;
        } catch (Throwable) {
            return [];
        }
    }

    public static function recommendedEmailColumn(mixed $uploadState): ?string
    {
        $options = self::emailColumnOptions($uploadState);

        return $options === [] ? null : array_key_first($options);
    }

    public static function resolveUploadAbsolutePath(mixed $state): ?string
    {
        $file = is_array($state) ? reset($state) : $state;

        if ($file instanceof TemporaryUploadedFile) {
            $path = $file->getRealPath();

            return (is_string($path) && is_file($path)) ? $path : null;
        }

        if (! is_string($file) || $file === '') {
            return null;
        }

        if (Storage::disk('local')->exists($file)) {
            return Storage::disk('local')->path($file);
        }

        return is_file($file) ? $file : null;
    }
}
