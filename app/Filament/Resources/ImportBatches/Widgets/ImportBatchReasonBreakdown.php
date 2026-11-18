<?php

namespace App\Filament\Resources\ImportBatches\Widgets;

use App\Models\ImportBatch;
use App\Support\CleaningUi;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\Locked;

class ImportBatchReasonBreakdown extends TableWidget
{
    protected static bool $isDiscovered = false;

    #[Locked]
    public ?ImportBatch $record = null;

    public bool $showAllChecks = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Why leads were removed';

    public static function canView(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Why leads were removed')
            ->records(fn (): array => $this->record
                ? CleaningUi::removalReasonRows($this->record, $this->showAllChecks)
                : [])
            ->columns([
                TextColumn::make('label')
                    ->label('Check')
                    ->wrap(),
                TextColumn::make('count')
                    ->label('Count')
                    ->alignEnd()
                    ->sortable(false),
                TextColumn::make('percent')
                    ->label('% of total')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 1).'%'),
            ])
            ->paginated(false)
            ->headerActions([
                Action::make('toggle_zeros')
                    ->label(fn (): string => $this->showAllChecks ? 'Hide zero checks' : 'Show all checks')
                    ->action(function (): void {
                        $this->showAllChecks = ! $this->showAllChecks;
                        $this->resetTable();
                    }),
            ])
            ->emptyStateHeading('No removals')
            ->emptyStateDescription('Every lead in this file passed the removal checks (or is in Final / Later / Gateway).');
    }
}
