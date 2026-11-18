<?php

use App\Domain\Cleaning\CleaningPipeline;
use App\Domain\Cleaning\EmailNormaliser;
use App\Domain\Cleaning\LeadContext;
use App\Domain\Cleaning\Stages\GeneratedAddressStage;
use App\Domain\Cleaning\Stages\InFileDuplicateStage;
use App\Domain\Cleaning\Stages\RoleAddressStage;
use App\Domain\Cleaning\Stages\SyntaxStage;
use App\Domain\Cleaning\Stages\TypoDomainStage;
use App\Enums\LeadVerdict;

it('rejects invalid email format', function () {
    $ctx = new LeadContext(['email' => 'not-an-email', '_email_col' => 'email'], 1);
    $ctx->email = 'not-an-email';
    $ctx = (new SyntaxStage)->handle($ctx);

    expect($ctx->verdict)->toBe(LeadVerdict::Rejected)
        ->and($ctx->primaryCode())->toBe('invalid_format');
});

it('treats gmail dots and plus tags as duplicates', function () {
    $n = new EmailNormaliser;
    expect($n->duplicateKey('c.laudia.j+uae@gmail.com'))->toBe($n->duplicateKey('claudiaj@gmail.com'));

    $stage = new InFileDuplicateStage;
    $a = new LeadContext(['email' => 'c.laudia.j+uae@gmail.com', '_email_col' => 'email'], 1);
    $a->email = 'c.laudia.j+uae@gmail.com';
    $a->duplicateKey = $n->duplicateKey($a->email);
    $stage->handle($a);

    $b = new LeadContext(['email' => 'claudiaj@gmail.com', '_email_col' => 'email'], 2);
    $b->email = 'claudiaj@gmail.com';
    $b->duplicateKey = $n->duplicateKey($b->email);
    $stage->handle($b);

    expect($b->verdict)->toBe(LeadVerdict::Rejected)
        ->and($b->primaryCode())->toBe('duplicate');
});

it('rejects role addresses', function () {
    $ctx = new LeadContext(['email' => 'info@acme.com', '_email_col' => 'email'], 1);
    $ctx->email = 'info@acme.com';
    $ctx->localPart = 'info';
    $ctx->domain = 'acme.com';
    $ctx = (new RoleAddressStage(new EmailNormaliser))->handle($ctx);

    expect($ctx->verdict)->toBe(LeadVerdict::Rejected)
        ->and($ctx->primaryCode())->toBe('role_address');
});

it('rejects typo domains', function () {
    $ctx = new LeadContext(['email' => 'a@gmial.com', '_email_col' => 'email'], 1);
    $ctx->email = 'a@gmial.com';
    $ctx->localPart = 'a';
    $ctx->domain = 'gmial.com';
    $ctx = (new TypoDomainStage)->handle($ctx);

    expect($ctx->verdict)->toBe(LeadVerdict::Rejected)
        ->and($ctx->primaryCode())->toBe('typo_domain')
        ->and($ctx->suggestedFix)->toBe('gmail.com');
});

it('rejects generated looking addresses', function () {
    $ctx = new LeadContext(['email' => 'claudiajpierson796@gmail.com', '_email_col' => 'email'], 1);
    $ctx->email = 'claudiajpierson796@gmail.com';
    $ctx->localPart = 'claudiajpierson796';
    $ctx->domain = 'gmail.com';
    $ctx = (new GeneratedAddressStage)->handle($ctx);

    expect($ctx->verdict)->toBe(LeadVerdict::Rejected)
        ->and($ctx->primaryCode())->toBe('generated_address');
});

it('exposes all reason codes including safe', function () {
    expect(CleaningPipeline::reasonCodes())->toContain('safe', 'gateway', 'later_company_cap', 'duplicate');
});
