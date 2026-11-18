<?php

namespace App\Models;

use App\Enums\TicketAccessState;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $hidden = ['qr_token_hash'];

    protected $fillable = ['reference', 'status'];

    protected function casts(): array
    {
        return ['status' => TicketStatus::class, 'access_state' => TicketAccessState::class];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function reservationItem(): BelongsTo
    {
        return $this->belongsTo(ReservationItem::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class);
    }
}
