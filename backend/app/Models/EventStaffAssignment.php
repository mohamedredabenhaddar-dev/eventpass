<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventStaffAssignment extends Model
{
    protected $fillable = ['event_id', 'staff_user_id', 'assigned_by'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
