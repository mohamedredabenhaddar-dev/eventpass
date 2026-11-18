<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Console\Command;

class ExpirePendingReservations extends Command
{
    protected $signature = 'reservations:expire-pending';

    protected $description = 'Release inventory held by expired unpaid reservations';

    public function handle(ReservationService $reservations): int
    {
        Reservation::query()->where('status', 'pending_payment')->where('payment_expires_at', '<=', now())
            ->select('id')->orderBy('id')->chunkById(100, function ($rows) use ($reservations) {
                foreach ($rows as $row) {
                    $reservations->expire($row);
                }
            });

        return self::SUCCESS;
    }
}
