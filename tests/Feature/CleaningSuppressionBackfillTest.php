<?php

use App\Domain\Suppression\CleaningSuppressionBackfill;
use App\Enums\LeadVerdict;
use App\Enums\SuppressionReason;
use App\Enums\WorkspaceRole;
use App\Models\BatchLead;
use App\Models\ImportBatch;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use App\Support\SuppressionSources;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->workspace = Workspace::query()->create(['name' => 'WS']);
    $this->user = User::query()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Admin',
        'email' => 'admin@backfill.test',
        'password' => Hash::make('password'),
        'role' => WorkspaceRole::Owner,
        'must_change_password' => false,
    ]);
});

it('backfills leadshield suppressions from rejected batch leads', function () {
    $batch = ImportBatch::query()->withoutGlobalScopes()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'original_filename' => 'done.csv',
        'storage_path' => 'imports/tmp/done.csv',
        'status' => 'completed',
        'total_rows' => 2,
        'processed_rows' => 2,
    ]);

    BatchLead::query()->insert([
        [
            'import_batch_id' => $batch->id,
            'lead_id' => null,
            'row_number' => 1,
            'raw' => json_encode(['email' => 'dead@no-such-domain-xyz.invalid', '_email_col' => 'email']),
            'verdict' => LeadVerdict::Rejected->value,
            'risk_score' => 0,
            'reasons' => json_encode([['code' => 'domain_nxdomain', 'message' => 'Domain does not exist']]),
            'suggested_fix' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'import_batch_id' => $batch->id,
            'lead_id' => null,
            'row_number' => 2,
            'raw' => json_encode(['email' => 'dup@gmail.com', '_email_col' => 'email']),
            'verdict' => LeadVerdict::Rejected->value,
            'risk_score' => 0,
            'reasons' => json_encode([['code' => 'duplicate', 'message' => 'Duplicate']]),
            'suggested_fix' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $result = app(CleaningSuppressionBackfill::class)->fromBatch($batch);

    expect($result['created'])->toBe(1)
        ->and(
            Suppression::query()->withoutGlobalScopes()
                ->where('workspace_id', $this->workspace->id)
                ->where('source', SuppressionSources::LEADSHIELD)
                ->where('reason', SuppressionReason::Cleaning->value)
                ->where('value', 'dead@no-such-domain-xyz.invalid')
                ->exists()
        )->toBeTrue()
        ->and(
            Suppression::query()->withoutGlobalScopes()
                ->where('value', 'dup@gmail.com')
                ->exists()
        )->toBeFalse();
});
