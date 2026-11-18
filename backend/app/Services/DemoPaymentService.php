<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Simulates payment outcomes locally. This service never contacts a payment provider. */
class DemoPaymentService
{
    public function createAttempt(User $user, Reservation $reservation): Payment
    {
        $snapshot = Reservation::query()->select(['id', 'event_id'])->findOrFail($reservation->id);

        return DB::transaction(function () use ($user, $snapshot) {
            Event::query()->lockForUpdate()->findOrFail($snapshot->event_id);
            $locked = Reservation::query()->with('items')->lockForUpdate()->findOrFail($snapshot->id);
            abort_unless($locked->user_id === $user->getKey(), 404);
            abort_unless($locked->status === ReservationStatus::PendingPayment, 409, 'This reservation is not awaiting payment.');
            abort_unless($locked->payment_expires_at && $locked->payment_expires_at->isFuture(), 409, 'This reservation has expired.');
            abort_unless($locked->event->status === EventStatus::Published && $locked->event->start_at->isFuture(), 409, 'This event is no longer accepting payment.');
            $this->assertServerTotal($locked);

            $existing = $locked->payments()->where('status', PaymentAttemptStatus::Pending->value)->lockForUpdate()->first();
            if ($existing) {
                return $existing->load('reservation');
            }

            $payment = $locked->payments()->create([
                'payment_reference' => 'DEMO-'.Str::upper((string) Str::ulid()),
                'amount' => $locked->total,
                'currency' => config('ticketing.currency', 'MAD'),
                'status' => PaymentAttemptStatus::Pending,
                'payment_method' => 'demo',
            ]);
            $locked->payment_status = PaymentStatus::Pending;
            $locked->save();

            return $payment->load('reservation');
        }, attempts: 3);
    }

    public function simulate(User $user, Payment $payment, bool $succeed): Payment
    {
        $snapshot = Payment::query()->with('reservation:id,event_id')->findOrFail($payment->id);
        $reservationSnapshot = $snapshot->reservation;

        return DB::transaction(function () use ($user, $snapshot, $reservationSnapshot, $succeed) {
            $event = Event::query()->lockForUpdate()->findOrFail($reservationSnapshot->event_id);
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservationSnapshot->id);
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($snapshot->id);
            abort_unless($reservation->user_id === $user->getKey(), 404);

            if ($lockedPayment->status === PaymentAttemptStatus::Succeeded) {
                abort_unless($reservation->payment_status === PaymentStatus::Paid && $reservation->status === ReservationStatus::Confirmed, 409, 'Payment and reservation state are inconsistent.');

                return $lockedPayment->load('reservation');
            }
            abort_unless($lockedPayment->status === PaymentAttemptStatus::Pending, 409, 'This payment attempt has already been processed.');
            abort_unless($reservation->status === ReservationStatus::PendingPayment && $reservation->payment_expires_at?->isFuture(), 409, 'This reservation is no longer payable.');
            abort_unless($reservation->payment_status === PaymentStatus::Pending, 409, 'This reservation is not awaiting payment.');
            abort_unless($event->status === EventStatus::Published && $event->start_at->isFuture(), 409, 'This event is no longer accepting payment.');
            $this->assertServerTotal($reservation);
            abort_unless($this->moneyToCents($lockedPayment->amount) === $this->moneyToCents($reservation->total), 409, 'Payment amount no longer matches the reservation.');

            if ($succeed) {
                $lockedPayment->status = PaymentAttemptStatus::Succeeded;
                $lockedPayment->paid_at = now();
                $lockedPayment->failure_reason = null;
                $lockedPayment->save();
                $reservation->status = ReservationStatus::Confirmed;
                $reservation->payment_status = PaymentStatus::Paid;
                $reservation->payment_expires_at = null;
                $reservation->save();
                $reservation->tickets()->where('status', TicketStatus::Pending->value)
                    ->update(['status' => TicketStatus::Valid->value, 'updated_at' => now()]);
                $reservation->payments()->where('id', '!=', $lockedPayment->id)->where('status', PaymentAttemptStatus::Pending->value)
                    ->update(['status' => PaymentAttemptStatus::Expired->value, 'updated_at' => now()]);
            } else {
                $lockedPayment->status = PaymentAttemptStatus::Failed;
                $lockedPayment->failure_reason = 'Demo payment was marked as failed by the client simulation.';
                $lockedPayment->save();
                $reservation->payment_status = PaymentStatus::Failed;
                $reservation->save();
            }

            return $lockedPayment->load('reservation');
        }, attempts: 3);
    }

    private function assertServerTotal(Reservation $reservation): void
    {
        $subtotalCents = $reservation->items->sum(fn ($item) => $this->moneyToCents($item->subtotal));
        abort_unless($subtotalCents === $this->moneyToCents($reservation->total), 409, 'Reservation total does not match its ticket items.');
    }

    private function moneyToCents(string|int|float $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
