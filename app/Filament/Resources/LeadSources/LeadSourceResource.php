<?php

namespace App\Filament\Resources\LeadSources;

use App\Filament\Resources\LeadSources\Pages\ManageLeadSources;
use App\Models\LeadSource;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LeadSourceResource extends Resource
{
    protected static ?string $model = LeadSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Data';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                Select::make('type')
                    ->options([
                        'bought' => 'Bought',
                        'scraped' => 'Scraped',
                        'platform' => 'Platform',
                        'inbound' => 'Inbound',
                        'manual' => 'Manual',
                    ])
                    ->required(),
                TextInput::make('vendor')->maxLength(255),
                DatePicker::make('acquired_at'),
                Textarea::make('notes')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('vendor')->toggleable(),
                TextColumn::make('acquired_at')->date()->sortable(),
                TextColumn::make('quality_score')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLeadSources::route('/'),
        ];
    }
}
