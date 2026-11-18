<?php

namespace App\Domain\Cleaning\Stages;

use App\Domain\Cleaning\CleaningStage;
use App\Domain\Cleaning\LeadContext;
use App\Domain\Import\ListCleanReader;
use App\Domain\Suppression\SuppressionWriter;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;

class ListCleanStage implements CleaningStage
{
    /**
     * @param  array<string, string>|null  $statusByEmail
     */
    public function __construct(
        private ?array $statusByEmail,
        private ListCleanReader $reader,
        private ?SuppressionWriter $writer = null,
        private ?int $workspaceId = null,
        private ?int $userId = null,
    ) {}

    public function key(): string
    {
        return 'listclean';
    }

    public function handle(LeadContext $ctx): LeadContext
    {
        if ($ctx->stopped || $this->statusByEmail === null) {
            return $ctx;
        }

        $status = $this->statusByEmail[$ctx->email] ?? null;

        if ($status === null) {
            $ctx->reject('listclean_missing', 'Not marked clean in ListClean file');

            return $ctx;
        }

        if ($this->reader->isClean($status)) {
            return $ctx;
        }

        if ($this->writer && $this->workspaceId && $this->reader->isDirty($status)) {
            $this->writer->upsert(
                workspaceId: $this->workspaceId,
                type: SuppressionType::Email,
                value: $ctx->email,
                reason: SuppressionReason::Dirty,
                source: 'listclean',
                note: 'ListClean status: '.$status,
                createdBy: $this->userId,
            );
        }

        $ctx->reject('listclean_dirty', 'ListClean: '.$status);

        return $ctx;
    }
}
