<?php

namespace App\Filament\Resources\LeadSources\Pages;

use App\Filament\Resources\LeadSources\LeadSourceResource;
use App\Models\LeadSource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLeadSources extends ManageRecords
{
    protected static string $resource = LeadSourceResource::class;

    public function getHeading(): string
    {
        return 'Lead Sources ('.number_format(LeadSource::query()->count()).')';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
