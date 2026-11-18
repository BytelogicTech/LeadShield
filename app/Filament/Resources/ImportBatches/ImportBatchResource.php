<?php

namespace App\Filament\Resources\ImportBatches;

use App\Filament\Resources\ImportBatches\Pages\CreateImportBatch;
use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Models\ImportBatch;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                    ->storeFileNamesIn('original_filename'),
                TextInput::make('email_column')
                    ->label('Email column name (optional)')
                    ->helperText('Leave blank to auto-detect.'),
                Select::make('lead_source_id')
                    ->label('Lead source (optional)')
                    ->relationship('leadSource', 'name')
                    ->searchable()
                    ->preload(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('original_filename')->label('File'),
                TextEntry::make('status')->badge(),
                TextEntry::make('dns_status')->label('DNS'),
                TextEntry::make('progress')
                    ->label('Progress')
                    ->state(fn (ImportBatch $record): string => $record->total_rows > 0
                        ? $record->processed_rows.' / '.$record->total_rows
                        : (string) $record->processed_rows),
                TextEntry::make('stats.totals.safe')->label('Safe (Final)'),
                TextEntry::make('stats.totals.gateway')->label('Gateway'),
                TextEntry::make('stats.totals.later')->label('Later'),
                TextEntry::make('stats.totals.rejected')->label('Removed'),
                TextEntry::make('stats.error')->label('Error')->visible(fn (ImportBatch $r) => filled(data_get($r->stats, 'error'))),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('original_filename')->searchable()->limit(40),
                TextColumn::make('status')->badge(),
                TextColumn::make('processed_rows')->label('Processed'),
                TextColumn::make('total_rows')->label('Total'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportBatches::route('/'),
            'create' => CreateImportBatch::route('/create'),
            'view' => ViewImportBatch::route('/{record}'),
        ];
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }
}
