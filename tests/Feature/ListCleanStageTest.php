<?php

use App\Domain\Cleaning\EmailNormaliser;
use App\Domain\Cleaning\LeadContext;
use App\Domain\Cleaning\Stages\ListCleanStage;
use App\Domain\Import\ListCleanReader;
use App\Domain\Suppression\SuppressionWriter;
use App\Enums\LeadVerdict;
use App\Enums\SuppressionReason;
use App\Enums\WorkspaceRole;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->workspace = Workspace::query()->create(['name' => 'WS']);
    $this->user = User::query()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Admin',
        'email' => 'a@test.local',
        'password' => Hash::make('password'),
        'role' => WorkspaceRole::Owner,
        'must_change_password' => false,
    ]);
});

it('keeps listclean clean emails', function () {
    $reader = new ListCleanReader(new EmailNormaliser);
    $stage = new ListCleanStage(['ok@gmail.com' => 'clean'], $reader);
    $ctx = new LeadContext(['email' => 'ok@gmail.com'], 1);
    $ctx->email = 'ok@gmail.com';

    $stage->handle($ctx);

    expect($ctx->verdict)->toBe(LeadVerdict::Safe)
        ->and($ctx->stopped)->toBeFalse();
});

it('rejects dirty listclean emails and adds suppression', function () {
    $reader = new ListCleanReader(new EmailNormaliser);
    $stage = new ListCleanStage(
        ['bad@gmail.com' => 'dirty'],
        $reader,
        new SuppressionWriter,
        (int) $this->workspace->id,
        $this->user->id,
    );
    $ctx = new LeadContext(['email' => 'bad@gmail.com'], 1);
    $ctx->email = 'bad@gmail.com';

    $stage->handle($ctx);

    expect($ctx->verdict)->toBe(LeadVerdict::Rejected)
        ->and($ctx->primaryCode())->toBe('listclean_dirty')
        ->and(
            Suppression::query()->withoutGlobalScopes()
                ->where('workspace_id', $this->workspace->id)
                ->where('value', 'bad@gmail.com')
                ->where('reason', SuppressionReason::Dirty->value)
                ->exists()
        )->toBeTrue();
});

it('rejects emails missing from listclean when file was provided', function () {
    $reader = new ListCleanReader(new EmailNormaliser);
    $stage = new ListCleanStage(['only@gmail.com' => 'clean'], $reader);
    $ctx = new LeadContext(['email' => 'other@gmail.com'], 1);
    $ctx->email = 'other@gmail.com';

    $stage->handle($ctx);

    expect($ctx->primaryCode())->toBe('listclean_missing');
});
