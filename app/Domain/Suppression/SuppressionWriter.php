<?php

namespace App\Domain\Suppression;

use App\Enums\BounceDetail;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Suppression;

class SuppressionWriter
{
    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function upsert(
        int $workspaceId,
        SuppressionType $type,
        string $value,
        SuppressionReason $reason,
        string $source,
        ?BounceDetail $bounceDetail = null,
        ?string $note = null,
        ?int $createdBy = null,
    ): array {
        $value = strtolower(trim($value));
        $value = ltrim($value, '@');
        if ($value === '') {
            return ['created' => 0, 'updated' => 0, 'skipped' => 1];
        }

        if ($type === SuppressionType::Email && ! str_contains($value, '@')) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 1];
        }

        $hash = Suppression::hashValue($value);
        $existing = Suppression::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('type', $type->value)
            ->where('value_hash', $hash)
            ->first();

        $payload = [
            'value' => $value,
            'reason' => $reason->value,
            'source' => $source,
            'bounce_detail' => $bounceDetail?->value,
            'permanent' => true,
            'note' => $note,
            'created_by' => $createdBy,
            'deleted_at' => null,
        ];

        if ($existing) {
            $existing->fill($payload)->save();

            return ['created' => 0, 'updated' => 1, 'skipped' => 0];
        }

        Suppression::query()->withoutGlobalScopes()->create([
            'workspace_id' => $workspaceId,
            'type' => $type->value,
            'value' => $value,
            'value_hash' => $hash,
            ...$payload,
        ]);

        return ['created' => 1, 'updated' => 0, 'skipped' => 0];
    }
}
