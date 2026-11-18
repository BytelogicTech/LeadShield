<?php

use App\Domain\Import\ImportBatchProcessor;
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
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->workspace = Workspace::query()->create(['name' => 'WS']);
    $this->user = User::query()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Admin',
        'email' => 'admin@import.test',
        'password' => Hash::make('password'),
        'role' => WorkspaceRole::Owner,
        'must_change_password' => false,
    ]);
});

it('sets total rows before cleaning and adds removed emails to leadshield suppressions', function () {
    $relative = 'imports/tmp/clean_me.csv';
    Storage::disk('local')->put($relative, implode("\n", [
        'email,company',
        'good@gmail.com,Acme',
        'info@acme.com,Acme',
        'bad@mailinator.com,SpamCo',
    ])."\n");

    $batch = ImportBatch::query()->withoutGlobalScopes()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'original_filename' => 'clean_me.csv',
        'storage_path' => $relative,
        'column_mapping' => ['email' => 'email'],
        'status' => 'queued',
        'total_rows' => 0,
        'processed_rows' => 0,
    ]);

    app(ImportBatchProcessor::class)->process($batch->fresh());

    $batch->refresh();

    expect($batch->status)->toBe('completed')
        ->and($batch->total_rows)->toBe(3)
        ->and($batch->processed_rows)->toBe(3);

    $verdicts = BatchLead::query()
        ->where('import_batch_id', $batch->id)
        ->pluck('verdict')
        ->map(fn ($v) => $v instanceof LeadVerdict ? $v->value : $v)
        ->all();

    expect($verdicts)->toContain(LeadVerdict::Safe->value)
        ->and($verdicts)->toContain(LeadVerdict::Rejected->value);

    expect(
        Suppression::query()->withoutGlobalScopes()
            ->where('workspace_id', $this->workspace->id)
            ->where('source', SuppressionSources::LEADSHIELD)
            ->where('reason', SuppressionReason::Cleaning->value)
            ->whereIn('value', ['info@acme.com', 'bad@mailinator.com'])
            ->count()
    )->toBeGreaterThanOrEqual(1);
});

it('respects the mapped email column when multiple email columns exist', function () {
    $relative = 'imports/tmp/two_emails.csv';
    Storage::disk('local')->put($relative, implode("\n", [
        'name,work_email,personal_email',
        'Ada,ada@gmail.com,ada.personal@mailinator.com',
    ])."\n");

    $batch = ImportBatch::query()->withoutGlobalScopes()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'original_filename' => 'two_emails.csv',
        'storage_path' => $relative,
        'column_mapping' => ['email' => 'work_email'],
        'status' => 'queued',
        'total_rows' => 0,
        'processed_rows' => 0,
    ]);

    app(ImportBatchProcessor::class)->process($batch->fresh());
    $batch->refresh();

    expect($batch->status)->toBe('completed')
        ->and(data_get($batch->column_mapping, 'email'))->toBe('work_email');

    $lead = BatchLead::query()->where('import_batch_id', $batch->id)->first();
    expect($lead?->verdict)->toBe(LeadVerdict::Safe);
});
