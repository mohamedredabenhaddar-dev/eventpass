<?php

namespace App\Http\Resources;

use App\Enums\EventStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_reference' => $this->payment_reference,
            'reservation_id' => $this->reservation_id,
            'reservation_reference' => $this->whenLoaded('reservation', fn () => $this->reservation->reference),
            'reservation_status' => $this->whenLoaded('reservation', fn () => $this->reservation->status->value),
            'reservation_payment_status' => $this->whenLoaded('reservation', fn () => $this->reservation->payment_status->value),
            'reservation_payment_expires_at' => $this->whenLoaded('reservation', fn () => $this->reservation->payment_expires_at?->toISOString()),
            'retry_allowed' => $this->whenLoaded('reservation', function () {
                $reservation = $this->reservation;

                return $this->status === PaymentAttemptStatus::Failed
                    && $reservation->status === ReservationStatus::PendingPayment
                    && $reservation->payment_status === PaymentStatus::Failed
                    && $reservation->payment_expires_at?->isFuture()
                    && $reservation->event?->status === EventStatus::Published
                    && $reservation->event?->start_at?->isFuture();
            }),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'payment_method' => 'demo',
            'mode' => 'DEMO',
            'paid_at' => $this->paid_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
