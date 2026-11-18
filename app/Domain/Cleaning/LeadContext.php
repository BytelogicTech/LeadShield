<?php

namespace App\Domain\Cleaning;

use App\Enums\LeadVerdict;

class LeadContext
{
    public string $email = '';

    public string $localPart = '';

    public string $domain = '';

    public string $duplicateKey = '';

    public LeadVerdict $verdict = LeadVerdict::Safe;

    /** @var list<array{code: string, message: string}> */
    public array $reasons = [];

    public ?string $suggestedFix = null;

    public ?string $mxProvider = null;

    public ?string $dnsStatus = null;

    public bool $stopped = false;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public array $raw,
        public int $rowNumber,
    ) {}

    public function reject(string $code, string $message): void
    {
        $this->verdict = LeadVerdict::Rejected;
        $this->reasons[] = ['code' => $code, 'message' => $message];
        $this->stopped = true;
    }

    public function markGateway(string $code, string $message): void
    {
        if ($this->verdict === LeadVerdict::Rejected) {
            return;
        }

        $this->verdict = LeadVerdict::Gateway;
        $this->reasons[] = ['code' => $code, 'message' => $message];
        $this->stopped = true;
    }

    public function markLater(string $code, string $message): void
    {
        if (in_array($this->verdict, [LeadVerdict::Rejected, LeadVerdict::Gateway], true)) {
            return;
        }

        $this->verdict = LeadVerdict::Later;
        $this->reasons[] = ['code' => $code, 'message' => $message];
        $this->stopped = true;
    }

    public function primaryReason(): string
    {
        return $this->reasons[0]['message'] ?? '';
    }

    public function primaryCode(): string
    {
        return $this->reasons[0]['code'] ?? '';
    }
}
