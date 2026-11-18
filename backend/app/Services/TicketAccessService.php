<?php

namespace App\Services;

use App\Enums\AccessAction;
use App\Enums\EventStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketAccessState;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\AccessLog;
use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TicketAccessService
{
    public function scan(User $staff, string $plainToken, AccessAction $action, ?string $gate): array
    {
        $tokenHash = hash('sha256', $plainToken);
        $snapshot = Ticket::query()->where('qr_token_hash', $tokenHash)->with('reservation:id,event_id')->first();
        abort_unless($snapshot, 404, 'Invalid QR credential.');

        return DB::transaction(function () use ($staff, $tokenHash, $action, $gate, $snapshot) {
            $event = Event::query()->lockForUpdate()->findOrFail($snapshot->reservation->event_id);
            abort_unless($staff->role === UserRole::Staff, 403, 'Only staff accounts can scan tickets.');

            $assignment = EventStaffAssignment::query()
                ->where('event_id', $event->id)
                ->where('staff_user_id', $staff->getKey())
                ->lockForUpdate()
                ->first();
            abort_unless($assignment, 403, 'You are not assigned to this event.');
            abort_unless($event->status === EventStatus::Published, 409, 'This event is not currently accepting scans.');

            $ticket = Ticket::query()->with('reservation')->lockForUpdate()->findOrFail($snapshot->id);
            abort_unless(is_string($ticket->qr_token_hash) && hash_equals($ticket->qr_token_hash, $tokenHash), 404, 'Invalid QR credential.');
            abort_unless($ticket->status === TicketStatus::Valid && $ticket->reservation->status === ReservationStatus::Confirmed && $ticket->reservation->payment_status === PaymentStatus::Paid, 409, 'This ticket has been cancelled or is invalid.');

            $state = $ticket->access_state;
            if ($action === AccessAction::Entry) {
                abort_unless($state !== TicketAccessState::Inside, 409, 'This ticket is already inside.');
                $ticket->access_state = TicketAccessState::Inside;
            } else {
                abort_unless($state !== TicketAccessState::NotEntered, 409, 'This ticket has not entered yet.');
                abort_unless($state === TicketAccessState::Inside, 409, 'This ticket is already outside.');
                $ticket->access_state = TicketAccessState::Outside;
            }

            $ticket->save();
            $log = new AccessLog(['action' => $action, 'gate' => $gate, 'scanned_at' => now()]);
            $log->ticket()->associate($ticket);
            $log->event()->associate($event);
            $log->staff()->associate($staff);
            $log->save();

            return [
                'ticket_reference' => $ticket->reference,
                'event_id' => $event->id,
                'action' => $action->value,
                'access_state' => $ticket->access_state->value,
                'gate' => $log->gate,
                'scanned_at' => $log->scanned_at->toISOString(),
            ];
        }, attempts: 3);
    }

    public function statistics(Event $event): array
    {
        $eligible = fn () => DB::table('tickets')
            ->join('reservations', 'reservations.id', '=', 'tickets.reservation_id')
            ->where('tickets.status', TicketStatus::Valid->value)
            ->where('reservations.status', ReservationStatus::Confirmed->value)
            ->where('reservations.event_id', $event->id);

        $inside = $eligible()->where('tickets.access_state', TicketAccessState::Inside->value)->count();
        $uniqueEntered = (clone $eligible())->whereExists(function ($query) {
            $query->selectRaw('1')->from('access_logs')
                ->whereColumn('access_logs.ticket_id', 'tickets.id')
                ->where('access_logs.action', AccessAction::Entry->value);
        })->count();
        $entries = DB::table('access_logs')->join('tickets', 'tickets.id', '=', 'access_logs.ticket_id')
            ->join('reservations', 'reservations.id', '=', 'tickets.reservation_id')
            ->where('access_logs.event_id', $event->id)->where('access_logs.action', AccessAction::Entry->value)
            ->where('tickets.status', TicketStatus::Valid->value)->where('reservations.status', ReservationStatus::Confirmed->value)->count();
        $exits = DB::table('access_logs')->join('tickets', 'tickets.id', '=', 'access_logs.ticket_id')
            ->join('reservations', 'reservations.id', '=', 'tickets.reservation_id')
            ->where('access_logs.event_id', $event->id)->where('access_logs.action', AccessAction::Exit->value)
            ->where('tickets.status', TicketStatus::Valid->value)->where('reservations.status', ReservationStatus::Confirmed->value)->count();

        return [
            'event_id' => $event->id,
            'attendees_inside' => $inside,
            'unique_tickets_entered' => $uniqueEntered,
            'successful_entries' => $entries,
            'successful_exits' => $exits,
        ];
    }
}
