<?php

use App\Enums\WorkspaceRole;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->workspace = Workspace::query()->create([
        'name' => 'Test Workspace',
        'settings' => ['free_provider_policy' => 'allow'],
    ]);

    $this->user = User::query()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Tester',
        'email' => 'tester@example.com',
        'password' => Hash::make('password'),
        'role' => WorkspaceRole::Owner,
        'must_change_password' => false,
    ]);
});

it('seeds a single workspace owner', function () {
    $this->seed();

    expect(Workspace::query()->where('name', 'Bytelogic')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'admin@bytelogic.local')->exists())->toBeTrue();
});

it('allows free provider policy by default', function () {
    expect(config('cleaning.free_provider_policy'))->toBe('allow');
});

it('keeps long session lifetime', function () {
    expect((int) config('session.lifetime'))->toBeGreaterThanOrEqual(525600);
});

it('stores suppressions with hashed values', function () {
    $this->actingAs($this->user);

    $suppression = Suppression::query()->create([
        'workspace_id' => $this->workspace->id,
        'type' => 'email',
        'value' => 'bounce@example.com',
        'value_hash' => Suppression::hashValue('bounce@example.com'),
        'reason' => 'manual',
        'source' => 'manual',
        'permanent' => true,
        'created_by' => $this->user->id,
    ]);

    expect($suppression->value_hash)->toBe(hash('sha256', 'bounce@example.com'))
        ->and(Suppression::query()->count())->toBe(1);
});

it('scopes suppressions to the authenticated workspace', function () {
    $other = Workspace::query()->create(['name' => 'Other']);

    Suppression::query()->withoutGlobalScopes()->create([
        'workspace_id' => $other->id,
        'type' => 'email',
        'value' => 'other@example.com',
        'value_hash' => Suppression::hashValue('other@example.com'),
        'reason' => 'manual',
        'source' => 'manual',
        'permanent' => true,
    ]);

    $this->actingAs($this->user);

    expect(Suppression::query()->count())->toBe(0);
});

it('purges old import files but never suppressions or dns cache', function () {
    $exit = Artisan::call('leadshield:purge-imports', ['--days' => 7]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Suppressions and DNS cache untouched');
});
