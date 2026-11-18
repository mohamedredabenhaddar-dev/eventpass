<?php

namespace App\Models;

use App\Enums\AccessAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AccessLog extends Model
{
    protected $fillable = ['action', 'gate', 'scanned_at'];

    protected function casts(): array
    {
        return ['action' => AccessAction::class, 'scanned_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Access logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Access logs are append-only.'));
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
