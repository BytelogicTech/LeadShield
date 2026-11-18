<?php

namespace App\Filament\Resources\CleaningRules\Pages;

use App\Filament\Resources\CleaningRules\CleaningRuleResource;
use App\Models\CleaningRule;
use Database\Seeders\CleaningRuleSeeder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageCleaningRules extends ManageRecords
{
    protected static string $resource = CleaningRuleResource::class;

    public function getHeading(): string
    {
        return 'Cleaning Rules ('.number_format(CleaningRule::query()->count()).')';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('seed_defaults')
                ->label('Load defaults')
                ->visible(fn (): bool => CleaningRule::query()->count() === 0)
                ->action(function (): void {
                    $workspaceId = (int) auth()->user()->workspace_id;
                    app(CleaningRuleSeeder::class)->seedForWorkspace($workspaceId);

                    Notification::make()
                        ->title('Default cleaning rules loaded')
                        ->success()
                        ->send();

                    $this->resetTable();
                }),
        ];
    }
}
