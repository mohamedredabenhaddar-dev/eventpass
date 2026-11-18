<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TicketCredentialService
{
    /** Issue or rotate a credential. Only its SHA-256 hash is persisted. */
    public function issueForOwner(User $user, Ticket $ticket): array
    {
        $snapshot = Ticket::query()->with('reservation:id,event_id')->findOrFail($ticket->id);

        return DB::transaction(function () use ($user, $snapshot) {
            $event = Event::query()->lockForUpdate()->findOrFail($snapshot->reservation->event_id);
            abort_unless($event->status === EventStatus::Published, 409, 'QR credentials are only available for published events.');

            $locked = Ticket::query()->with('reservation')->lockForUpdate()->findOrFail($snapshot->id);

            abort_unless($locked->reservation->user_id === $user->getKey(), 404);
            abort_unless($locked->status === TicketStatus::Valid && $locked->reservation->status === ReservationStatus::Confirmed && $locked->reservation->payment_status === PaymentStatus::Paid, 409, 'Only paid and confirmed tickets can receive a QR credential.');

            $token = self::generateToken();
            $locked->forceFill(['qr_token_hash' => hash('sha256', $token)])->save();

            return ['ticket_reference' => $locked->reference, 'qr_token' => $token];
        }, attempts: 3);
    }

    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
