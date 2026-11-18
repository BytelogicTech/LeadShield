<?php

namespace App\Domain\Suppression;

use App\Enums\BounceDetail;
use App\Enums\SuppressionReason;

class SmartleadReasonClassifier
{
    /**
     * @param  array<string, mixed>  $row
     * @return array{0: SuppressionReason, 1: BounceDetail|null}
     */
    public function classify(array $row): array
    {
        $status = strtolower(trim((string) ($row['lead_status'] ?? '')));
        $category = strtolower(trim((string) ($row['lead_category'] ?? '')));
        $reply = strtolower(trim((string) ($row['reply_message'] ?? '')));
        $isBounced = $this->truthy($row['is_bounced'] ?? null);
        $gotReply = $this->truthy($row['got_reply'] ?? null);

        if ($isBounced || str_contains($status, 'bounce') || str_contains($category, 'bounce')) {
            return [SuppressionReason::Bounced, $this->bounceDetail($reply)];
        }

        if (str_contains($status, 'unsub') || str_contains($category, 'unsub')) {
            return [SuppressionReason::Unsubscribed, null];
        }

        if (str_contains($category, 'not interested')
            || str_contains($category, 'not_interested')
            || str_contains($category, 'do not contact')
            || str_contains($status, 'not interested')) {
            return [SuppressionReason::NotInterested, null];
        }

        if ($gotReply || str_contains($status, 'repli') || str_contains($category, 'repli')) {
            return [SuppressionReason::Replied, null];
        }

        return [SuppressionReason::Contacted, null];
    }

    public function bounceDetail(string $replyMessage): BounceDetail
    {
        $t = strtolower($replyMessage);

        if ($t === '') {
            return BounceDetail::Unknown;
        }

        if (preg_match('/address not found|doesn.?t exist|user unknown|mailbox not found|no such user|recipient.*reject|5\.1\.1|5\.1\.10|wasn.?t found|invalid recipient/i', $t)) {
            return BounceDetail::AddressNotFound;
        }

        if (preg_match('/spam|blacklist|blocklist|reputation|surbl|spamhaus|suspects your message|policy|5\.7\.1|rejected as spam/i', $t)) {
            return BounceDetail::SpamRejection;
        }

        return BounceDetail::Unknown;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $v = strtolower(trim((string) $value));

        return in_array($v, ['1', 'true', 'yes', 'y', 'bounced'], true);
    }
}
