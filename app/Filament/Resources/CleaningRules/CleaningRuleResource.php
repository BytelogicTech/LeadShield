<?php

namespace App\Filament\Resources\CleaningRules;

use App\Domain\Cleaning\CleaningRuleCatalog;
use App\Filament\Resources\CleaningRules\Pages\ManageCleaningRules;
use App\Models\CleaningRule;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CleaningRuleResource extends Resource
{
    protected static ?string $model = CleaningRule::class;

    protected static ?string $navigationLabel = 'Cleaning Rules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('label')
                    ->label('Rule')
                    ->content(fn (?CleaningRule $record): string => $record
                        ? CleaningRuleCatalog::label((string) $record->key)
                        : '—'),
                Placeholder::make('about')
                    ->label('What it does')
                    ->content(fn (?CleaningRule $record): string => $record
                        ? CleaningRuleCatalog::description((string) $record->key)
                        : '—'),
                TextInput::make('key')->disabled()->dehydrated(),
                Toggle::make('enabled')
                    ->disabled(fn (?CleaningRule $record): bool => $record?->key === 'normalise'),
                KeyValue::make('params')
                    ->helperText('Optional stage settings (e.g. max_per_company_domain for the company cap).')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Rule')
                    ->formatStateUsing(fn (string $state): string => CleaningRuleCatalog::label($state))
                    ->description(fn (CleaningRule $record): string => CleaningRuleCatalog::description((string) $record->key))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                IconColumn::make('enabled')->boolean(),
                TextColumn::make('params')
                    ->label('Params')
                    ->formatStateUsing(function ($state): string {
                        if (! is_array($state) || $state === []) {
                            return '—';
                        }

                        return collect($state)
                            ->map(fn ($v, $k) => $k.'='.(is_scalar($v) ? $v : json_encode($v)))
                            ->implode(', ');
                    })
                    ->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('key')
            ->paginated([10, 25, 50])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCleaningRules::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
