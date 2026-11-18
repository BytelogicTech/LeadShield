<?php

namespace App\Domain\Cleaning\Stages;

use App\Domain\Cleaning\CleaningStage;
use App\Domain\Cleaning\LeadContext;
use App\Enums\BounceDetail;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Suppression;

class SuppressionStage implements CleaningStage
{
    /** @var array<string, true> */
    private array $emails = [];

    /** @var array<string, true> */
    private array $domains = [];

    /** @var array<string, true> */
    private array $bounceDomains = [];

    public function __construct(private int $workspaceId)
    {
        $rows = Suppression::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get(['type', 'value', 'reason', 'bounce_detail']);

        foreach ($rows as $row) {
            $value = strtolower(ltrim((string) $row->getAttribute('value'), '@'));
            $type = (string) ($row->getAttribute('type') instanceof \BackedEnum
                ? $row->getAttribute('type')->value
                : $row->getAttribute('type'));
            $reason = (string) ($row->getAttribute('reason') instanceof \BackedEnum
                ? $row->getAttribute('reason')->value
                : $row->getAttribute('reason'));
            $bounce = (string) ($row->getAttribute('bounce_detail') instanceof \BackedEnum
                ? $row->getAttribute('bounce_detail')->value
                : $row->getAttribute('bounce_detail'));

            $isHardBounce = $reason === SuppressionReason::Bounced->value
                && in_array($bounce, [
                    BounceDetail::AddressNotFound->value,
                    BounceDetail::SpamRejection->value,
                ], true);

            if ($type === SuppressionType::Email->value) {
                $this->emails[$value] = true;
                if ($isHardBounce && str_contains($value, '@')) {
                    $this->bounceDomains[substr($value, strrpos($value, '@') + 1)] = true;
                }
            } elseif ($type === SuppressionType::Domain->value) {
                $this->domains[$value] = true;
                if ($isHardBounce) {
                    $this->bounceDomains[$value] = true;
                }
            }
        }
    }

    public function key(): string
    {
        return 'suppression';
    }

    public function handle(LeadContext $ctx): LeadContext
    {
        if ($ctx->stopped) {
            return $ctx;
        }

        if (isset($this->emails[$ctx->email])) {
            $ctx->reject('suppressed_email', 'Already in suppression list');

            return $ctx;
        }

        if (isset($this->domains[$ctx->domain])) {
            $ctx->reject('suppressed_domain', 'Domain in suppression list');

            return $ctx;
        }

        $free = array_flip(config('cleaning.free_providers', []));
        if (! isset($free[$ctx->domain]) && isset($this->bounceDomains[$ctx->domain])) {
            $ctx->reject('suppressed_domain_bounce', 'Company domain has a hard bounce or spam rejection');
        }

        return $ctx;
    }
}
