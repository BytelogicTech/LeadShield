<?php

namespace App\Domain\Cleaning;

use App\Domain\Cleaning\Stages\DisposableStage;
use App\Domain\Cleaning\Stages\DomainIntelStage;
use App\Domain\Cleaning\Stages\GeneratedAddressStage;
use App\Domain\Cleaning\Stages\InFileDuplicateStage;
use App\Domain\Cleaning\Stages\NormaliseStage;
use App\Domain\Cleaning\Stages\PerCompanyCapStage;
use App\Domain\Cleaning\Stages\RoleAddressStage;
use App\Domain\Cleaning\Stages\SecureGatewayStage;
use App\Domain\Cleaning\Stages\SuppressionStage;
use App\Domain\Cleaning\Stages\SyntaxStage;
use App\Domain\Cleaning\Stages\TypoDomainStage;
use App\Domain\Dns\DnsResolver;

class CleaningPipeline
{
    private InFileDuplicateStage $duplicates;

    private PerCompanyCapStage $companyCap;

    /** @var list<CleaningStage> */
    private array $stages;

    public function __construct(
        private int $workspaceId,
        private DnsResolver $dns,
    ) {
        $this->duplicates = new InFileDuplicateStage;
        $this->companyCap = new PerCompanyCapStage;
        $normaliser = new EmailNormaliser;

        $this->stages = [
            new NormaliseStage($normaliser),
            new SyntaxStage,
            $this->duplicates,
            new RoleAddressStage($normaliser),
            new TypoDomainStage,
            new DisposableStage,
            new SuppressionStage($workspaceId),
            new GeneratedAddressStage,
            new DomainIntelStage($dns),
            new SecureGatewayStage($dns),
            $this->companyCap,
        ];
    }

    public function dns(): DnsResolver
    {
        return $this->dns;
    }

    public function resetBatchState(): void
    {
        $this->duplicates->reset();
        $this->companyCap->reset();
    }

    public function process(LeadContext $ctx): LeadContext
    {
        foreach ($this->stages as $stage) {
            if ($ctx->stopped) {
                break;
            }

            $ctx = $stage->handle($ctx);
        }

        return $ctx;
    }

    /**
     * @return list<string>
     */
    public static function reasonCodes(): array
    {
        return [
            'invalid_format',
            'duplicate',
            'role_address',
            'typo_domain',
            'disposable',
            'domain_nxdomain',
            'domain_no_mail',
            'domain_no_mx',
            'gateway',
            'generated_address',
            'suppressed_email',
            'suppressed_domain',
            'suppressed_domain_bounce',
            'later_company_cap',
            'safe',
        ];
    }
}
