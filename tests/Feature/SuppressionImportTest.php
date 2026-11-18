<?php

use App\Domain\Suppression\PlainBlocklistImporter;
use App\Domain\Suppression\SmartleadExportImporter;
use App\Domain\Suppression\SmartleadReasonClassifier;
use App\Enums\BounceDetail;
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
        'email' => 'admin@test.local',
        'password' => Hash::make('password'),
        'role' => WorkspaceRole::Owner,
        'must_change_password' => false,
    ]);
});

it('classifies smartlead bounce and spam rejection', function () {
    $c = new SmartleadReasonClassifier;

    [$reason, $detail] = $c->classify([
        'is_bounced' => 'true',
        'reply_message' => '550 5.1.1 Address not found',
    ]);
    expect($reason)->toBe(SuppressionReason::Bounced)
        ->and($detail)->toBe(BounceDetail::AddressNotFound);

    [$reason2, $detail2] = $c->classify([
        'is_bounced' => 1,
        'reply_message' => 'Message rejected as spam by reputation filters',
    ]);
    expect($reason2)->toBe(SuppressionReason::Bounced)
        ->and($detail2)->toBe(BounceDetail::SpamRejection);
});

it('imports every smartlead row into suppressions', function () {
    $path = storage_path('framework/testing/smartlead.csv');
    file_put_contents($path, implode("\n", [
        'email,lead_status,lead_category,is_bounced,got_reply,sent_time,reply_message',
        'a@gmail.com,completed,interested,false,true,2024-01-01,',
        'b@acme.com,bounced,bounce,true,false,2024-01-02,550 user unknown',
        'c@acme.com,completed,not interested,false,true,2024-01-03,no thanks',
    ]));

    $stats = app(SmartleadExportImporter::class)->import($path, (int) $this->workspace->id, $this->user->id);

    expect($stats['created'])->toBe(3)
        ->and(Suppression::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count())->toBe(3);

    $b = Suppression::query()->withoutGlobalScopes()->where('value', 'b@acme.com')->first();
    expect($b->reason)->toBe(SuppressionReason::Bounced)
        ->and($b->bounce_detail)->toBe(BounceDetail::AddressNotFound);

    $c = Suppression::query()->withoutGlobalScopes()->where('value', 'c@acme.com')->first();
    expect($c->reason)->toBe(SuppressionReason::NotInterested);

    @unlink($path);
});

it('imports plain emails and domains', function () {
    $path = storage_path('framework/testing/block.txt');
    file_put_contents($path, "x@gmail.com\n@blocked.com\npartner.org\n");

    $stats = app(PlainBlocklistImporter::class)->import($path, (int) $this->workspace->id, $this->user->id);

    expect($stats['created'])->toBe(3)
        ->and(Suppression::query()->withoutGlobalScopes()->where('value', 'blocked.com')->exists())->toBeTrue()
        ->and(Suppression::query()->withoutGlobalScopes()->where('value', 'partner.org')->exists())->toBeTrue();

    @unlink($path);
});
