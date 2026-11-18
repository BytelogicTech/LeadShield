<?php

namespace App\Domain\Cleaning\Stages;

use App\Domain\Cleaning\CleaningStage;
use App\Domain\Cleaning\LeadContext;

class PerCompanyCapStage implements CleaningStage
{
    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(
        private ?int $maxPerCompanyDomain = null,
    ) {}

    public function key(): string
    {
        return 'per_company_cap';
    }

    public function reset(): void
    {
        $this->counts = [];
    }

    public function handle(LeadContext $ctx): LeadContext
    {
        if ($ctx->stopped || $ctx->domain === '') {
            return $ctx;
        }

        $free = array_flip(config('cleaning.free_providers', []));
        if (isset($free[$ctx->domain])) {
            return $ctx;
        }

        $max = $this->maxPerCompanyDomain ?? (int) config('cleaning.max_per_company_domain', 1);
        $this->counts[$ctx->domain] = ($this->counts[$ctx->domain] ?? 0) + 1;

        if ($this->counts[$ctx->domain] > $max) {
            $ctx->markLater('later_company_cap', 'Extra lead for this company domain (kept for later)');
        }

        return $ctx;
    }
}
