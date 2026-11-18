<?php

namespace App\Domain\Cleaning;

use App\Domain\Cleaning\Stages\DisposableStage;
use App\Domain\Cleaning\Stages\DomainIntelStage;
use App\Domain\Cleaning\Stages\GeneratedAddressStage;
use App\Domain\Cleaning\Stages\InFileDuplicateStage;
use App\Domain\Cleaning\Stages\ListCleanStage;
use App\Domain\Cleaning\Stages\NormaliseStage;
use App\Domain\Cleaning\Stages\PerCompanyCapStage;
use App\Domain\Cleaning\Stages\RoleAddressStage;
use App\Domain\Cleaning\Stages\SecureGatewayStage;
use App\Domain\Cleaning\Stages\SuppressionStage;
use App\Domain\Cleaning\Stages\SyntaxStage;
use App\Domain\Cleaning\Stages\TypoDomainStage;
use App\Domain\Dns\DnsResolver;
use App\Domain\Import\ListCleanReader;
use App\Domain\Suppression\SuppressionWriter;
use App\Models\CleaningRule;

class CleaningPipeline
{
    private InFileDuplicateStage $duplicates;

    private PerCompanyCapStage $companyCap;

    /** @var list<CleaningStage> */
    private array $stages;

    /** @var array<string, bool>|null null = all enabled (no DB rules yet) */
    private ?array $enabledByKey = null;

    /**
     * @param  array<string, string>|null  $listCleanStatuses
     */
    public function __construct(
        private int $workspaceId,
        private DnsResolver $dns,
        ?array $listCleanStatuses = null,
        ?int $userId = null,
    ) {
        $ruleRows = CleaningRule::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->get(['key', 'enabled', 'params']);

        if ($ruleRows->isNotEmpty()) {
            $this->enabledByKey = [];
            foreach ($ruleRows as $row) {
                $this->enabledByKey[(string) $row->key] = (bool) $row->enabled;
            }
        }

        $capMax = data_get(
            $ruleRows->firstWhere('key', 'per_company_cap')?->params,
            'max_per_company_domain',
        );

        $this->duplicates = new InFileDuplicateStage;
        $this->companyCap = new PerCompanyCapStage(
            maxPerCompanyDomain: is_numeric($capMax) ? (int) $capMax : null,
        );
        $normaliser = new EmailNormaliser;
        $listCleanReader = new ListCleanReader($normaliser);

        $this->stages = [
            new NormaliseStage($normaliser),
            new SyntaxStage,
            $this->duplicates,
            new RoleAddressStage($normaliser),
            new TypoDomainStage,
            new DisposableStage,
            new ListCleanStage(
                $listCleanStatuses,
                $listCleanReader,
                $listCleanStatuses !== null ? new SuppressionWriter : null,
                $workspaceId,
                $userId,
            ),
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

            if (! $this->isStageEnabled($stage->key())) {
                continue;
            }

            $ctx = $stage->handle($ctx);
        }

        return $ctx;
    }

    private function isStageEnabled(string $key): bool
    {
        // Always normalise so later stages have email/domain populated.
        if ($key === 'normalise') {
            return true;
        }

        if ($this->enabledByKey === null) {
            return true;
        }

        return $this->enabledByKey[$key] ?? true;
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
            'listclean_dirty',
            'listclean_missing',
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
