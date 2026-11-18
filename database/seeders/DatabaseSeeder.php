<?php

namespace Database\Seeders;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (WorkspaceRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $workspace = Workspace::query()->firstOrCreate(
            ['name' => 'Bytelogic'],
            [
                'settings' => [
                    'free_provider_policy' => 'allow',
                    'purge_files_after_days' => 7,
                ],
            ],
        );

        $user = User::query()->updateOrCreate(
            ['email' => 'admin@bytelogic.local'],
            [
                'workspace_id' => $workspace->id,
                'name' => 'Bytelogic Admin',
                'password' => Hash::make('password'),
                'role' => WorkspaceRole::Owner,
                'must_change_password' => true,
                'email_verified_at' => now(),
            ],
        );

        $user->syncRoles([WorkspaceRole::Owner->value]);
    }
}
