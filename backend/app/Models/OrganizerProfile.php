<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizerProfile extends Model
{
    protected $fillable = ['user_id', 'organization_name', 'contact_email', 'contact_phone', 'description'];

    protected $casts = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
