<?php

namespace App\Filament\Resources\ImportBatches\RelationManagers;

use App\Enums\LeadVerdict;
use App\Models\BatchLead;
use App\Models\ImportBatch;
use App\Support\CleaningUi;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BatchLeadsRelationManager extends RelationManager
{
    protected static string $relationship = 'batchLeads';

    protected static ?string $title = 'All leads';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return in_array($ownerRecord->getAttribute('status'), ['completed', 'failed'], true);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * @return array<string | int, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'final' => Tab::make('Final')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('verdict', LeadVerdict::Safe->value)),
            'later' => Tab::make('Later')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('verdict', LeadVerdict::Later->value)),
            'gateway' => Tab::make('Gateway')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('verdict', LeadVerdict::Gateway->value)),
            'removed' => Tab::make('Removed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('verdict', LeadVerdict::Rejected->value)),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(function (): string {
                /** @var ImportBatch $batch */
                $batch = $this->getOwnerRecord();

                return 'All leads ('.number_format($batch->batchLeads()->count()).')';
            })
            ->defaultSort('row_number')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('email')
                    ->label('Email')
                    ->state(fn (BatchLead $record): string => CleaningUi::emailFromRaw($record->raw))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $inner) use ($search): void {
                            $inner->where('raw->email', 'like', "%{$search}%")
                                ->orWhere('raw', 'like', "%{$search}%");
                        });
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('raw->email', $direction);
                    })
                    ->wrap(),
                TextColumn::make('name')
                    ->label('Name')
                    ->state(fn (BatchLead $record): string => CleaningUi::nameFromRaw($record->raw) ?: '—')
                    ->toggleable(),
                TextColumn::make('company')
                    ->label('Company')
                    ->state(fn (BatchLead $record): string => CleaningUi::companyFromRaw($record->raw) ?: '—')
                    ->toggleable()
                    ->wrap(),
                TextColumn::make('verdict')
                    ->label('Result')
                    ->badge()
                    ->formatStateUsing(fn (?LeadVerdict $state): string => CleaningUi::resultLabel($state))
                    ->color(fn (?LeadVerdict $state): string => CleaningUi::resultColor($state))
                    ->sortable(),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->state(fn (BatchLead $record): string => CleaningUi::reasonDetail($record))
                    ->wrap()
                    ->searchable(false),
                TextColumn::make('mail_provider')
                    ->label('Mail provider')
                    ->state(fn (BatchLead $record): string => CleaningUi::mailProviderFor($record))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('verdict')
                    ->label('Result')
                    ->options([
                        LeadVerdict::Safe->value => 'Final',
                        LeadVerdict::Later->value => 'Later',
                        LeadVerdict::Gateway->value => 'Gateway',
                        LeadVerdict::Rejected->value => 'Removed',
                    ]),
                SelectFilter::make('reason_code')
                    ->label('Reason')
                    ->options(CleaningUi::reasonOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! filled($value)) {
                            return $query;
                        }

                        return $query->whereRaw(
                            "JSON_UNQUOTE(JSON_EXTRACT(reasons, '$[0].code')) = ?",
                            [(string) $value],
                        );
                    }),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
