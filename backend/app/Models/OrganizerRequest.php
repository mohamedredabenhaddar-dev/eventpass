<?php

namespace App\Models;

use App\Enums\OrganizerRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizerRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_name',
        'description',
        'contact_email',
        'contact_phone',
        'website',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrganizerRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
