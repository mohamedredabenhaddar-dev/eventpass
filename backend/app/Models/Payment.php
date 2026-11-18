<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'payment_reference',
        'amount',
        'currency',
        'status',
        'payment_method',
        'paid_at',
        'failure_reason',
    ];

    protected $hidden = ['failure_reason'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentAttemptStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
