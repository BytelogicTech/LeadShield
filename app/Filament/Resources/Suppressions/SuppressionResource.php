<?php

namespace App\Filament\Resources\Suppressions;

use App\Enums\BounceDetail;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Filament\Resources\Suppressions\Pages\ManageSuppressions;
use App\Models\Suppression;
use App\Support\SuppressionSources;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SuppressionResource extends Resource
{
    protected static ?string $model = Suppression::class;

    protected static ?string $navigationLabel = 'Suppressions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|\UnitEnum|null $navigationGroup = 'Hygiene';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->options(collect(SuppressionType::cases())->mapWithKeys(
                        fn (SuppressionType $t) => [$t->value => ucfirst($t->value)]
                    ))
                    ->required(),
                TextInput::make('value')
                    ->required()
                    ->helperText('Email address, or domain (with or without leading @).')
                    ->maxLength(255),
                Select::make('reason')
                    ->options(collect(SuppressionReason::cases())->mapWithKeys(
                        fn (SuppressionReason $r) => [$r->value => str_replace('_', ' ', ucfirst($r->value))]
                    ))
                    ->required()
                    ->live(),
                Select::make('bounce_detail')
                    ->options(collect(BounceDetail::cases())->mapWithKeys(
                        fn (BounceDetail $d) => [$d->value => str_replace('_', ' ', ucfirst($d->value))]
                    ))
                    ->visible(fn ($get) => $get('reason') === SuppressionReason::Bounced->value),
                Select::make('source')
                    ->options(SuppressionSources::options())
                    ->default(SuppressionSources::MANUAL)
                    ->required(),
                Toggle::make('permanent')->default(true),
                Textarea::make('note')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('value')->searchable()->sortable(),
                TextColumn::make('reason')->badge()->sortable(),
                TextColumn::make('bounce_detail')->toggleable(),
                TextColumn::make('source')
                    ->label('Where from')
                    ->formatStateUsing(fn (?string $state): string => $state ? SuppressionSources::label($state) : '—')
                    ->badge()
                    ->toggleable(),
                IconColumn::make('permanent')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'email' => 'Email',
                    'domain' => 'Domain',
                ]),
                SelectFilter::make('reason')->options(
                    collect(SuppressionReason::cases())->mapWithKeys(
                        fn (SuppressionReason $r) => [$r->value => str_replace('_', ' ', ucfirst($r->value))]
                    )->all()
                ),
                SelectFilter::make('source')
                    ->label('Where from')
                    ->options(SuppressionSources::options()),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([
                EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $value = strtolower(trim((string) ($data['value'] ?? '')));
                        $value = ltrim($value, '@');
                        $data['value'] = $value;
                        $data['value_hash'] = Suppression::hashValue($value);

                        return $data;
                    }),
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
            'index' => ManageSuppressions::route('/'),
        ];
    }
}
