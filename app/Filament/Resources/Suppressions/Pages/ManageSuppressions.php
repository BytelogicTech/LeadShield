<?php

namespace App\Filament\Resources\Suppressions\Pages;

use App\Filament\Resources\Suppressions\SuppressionResource;
use App\Models\Suppression;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSuppressions extends ManageRecords
{
    protected static string $resource = SuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $value = strtolower(trim((string) ($data['value'] ?? '')));
                    $value = ltrim($value, '@');
                    $data['value'] = $value;
                    $data['value_hash'] = Suppression::hashValue($value);
                    $data['created_by'] = auth()->id();

                    return $data;
                }),
        ];
    }
}
