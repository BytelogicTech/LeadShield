<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToWorkspace, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'email',
        'email_hash',
        'local_part',
        'domain',
        'first_name',
        'last_name',
        'company',
        'title',
        'country',
        'phone',
        'linkedin_url',
        'custom_fields',
        'first_seen_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
        ];
    }
}
