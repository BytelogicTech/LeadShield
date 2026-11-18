<?php

namespace App\Models;

use App\Enums\LeadVerdict;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $raw
 * @property array<int, array{code: string, message: string}>|null $reasons
 * @property LeadVerdict|null $verdict
 * @property string|null $suggested_fix
 */
class BatchLead extends Model
{
    protected $fillable = [
        'import_batch_id',
        'lead_id',
        'row_number',
        'raw',
        'verdict',
        'risk_score',
        'reasons',
        'suggested_fix',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'reasons' => 'array',
            'verdict' => LeadVerdict::class,
        ];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
