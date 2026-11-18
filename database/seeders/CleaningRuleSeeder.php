<?php

namespace Database\Seeders;

use App\Domain\Cleaning\CleaningRuleCatalog;
use App\Models\CleaningRule;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class CleaningRuleSeeder extends Seeder
{
    public function run(): void
    {
        Workspace::query()->each(function (Workspace $workspace): void {
            $this->seedForWorkspace((int) $workspace->id);
        });
    }

    public function seedForWorkspace(int $workspaceId): void
    {
        foreach (CleaningRuleCatalog::defaults() as $rule) {
            CleaningRule::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'key' => $rule['key'],
                ],
                [
                    'enabled' => $rule['enabled'],
                    'params' => $rule['params'],
                ],
            );
        }
    }
}
