<?php

namespace App\Models;

use App\Enums\BounceDetail;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Suppression extends Model
{
    use BelongsToWorkspace, LogsActivity, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'type',
        'value',
        'value_hash',
        'reason',
        'source',
        'bounce_detail',
        'permanent',
        'expires_at',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => SuppressionType::class,
            'reason' => SuppressionReason::class,
            'bounce_detail' => BounceDetail::class,
            'permanent' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    public static function hashValue(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }
}
