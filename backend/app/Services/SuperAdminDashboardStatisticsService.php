<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\EventStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use Illuminate\Support\Facades\DB;

class SuperAdminDashboardStatisticsService
{
    public function snapshot(): array
    {
        $now = now();

        return [
            'generated_at' => $now->toISOString(),
            'users' => [
                'total' => DB::table('users')->count(),
                'by_role' => $this->groupedCounts('users', 'role', UserRole::values()),
                'by_account_status' => $this->groupedCounts('users', 'account_status', AccountStatus::values()),
            ],
            'events' => [
                'total' => DB::table('events')->count(),
                'by_status' => $this->groupedCounts('events', 'status', $this->enumValues(EventStatus::class)),
                'published_upcoming' => DB::table('events')
                    ->where('status', EventStatus::Published->value)
                    ->where('start_at', '>', $now)
                    ->count(),
            ],
            'reservations' => [
                'total' => DB::table('reservations')->count(),
                'by_status' => $this->groupedCounts(
                    'reservations',
                    'status',
                    $this->enumValues(ReservationStatus::class),
                ),
                'by_payment_status' => $this->groupedCounts(
                    'reservations',
                    'payment_status',
                    $this->enumValues(PaymentStatus::class),
                ),
                'confirmed_paid' => DB::table('reservations')
                    ->where('status', ReservationStatus::Confirmed->value)
                    ->where('payment_status', PaymentStatus::Paid->value)
                    ->count(),
            ],
            'tickets' => [
                'total' => DB::table('tickets')->count(),
                'by_status' => $this->groupedCounts('tickets', 'status', $this->enumValues(TicketStatus::class)),
                'valid_for_confirmed_paid_reservations' => DB::table('tickets')
                    ->join('reservations', 'reservations.id', '=', 'tickets.reservation_id')
                    ->where('tickets.status', TicketStatus::Valid->value)
                    ->where('reservations.status', ReservationStatus::Confirmed->value)
                    ->where('reservations.payment_status', PaymentStatus::Paid->value)
                    ->count('tickets.id'),
            ],
            'demo_payments' => [
                'demo_only' => true,
                'attempts_by_status' => $this->groupedCounts(
                    'payments',
                    'status',
                    $this->enumValues(PaymentAttemptStatus::class),
                    'payment_method',
                    'demo',
                ),
                'successful_simulations' => DB::table('payments')
                    ->where('payment_method', 'demo')
                    ->where('status', PaymentAttemptStatus::Succeeded->value)
                    ->count(),
            ],
        ];
    }

    /** @param list<string> $values
     * @return array<string, int>
     */
    private function groupedCounts(
        string $table,
        string $column,
        array $values,
        ?string $filterColumn = null,
        ?string $filterValue = null,
    ): array {
        $counts = array_fill_keys($values, 0);

        $query = DB::table($table);

        if ($filterColumn !== null) {
            $query->where($filterColumn, $filterValue);
        }

        $rows = $query
            ->select($column, DB::raw('COUNT(*) as aggregate'))
            ->groupBy($column)
            ->get();

        foreach ($rows as $row) {
            if (array_key_exists($row->{$column}, $counts)) {
                $counts[$row->{$column}] = (int) $row->aggregate;
            }
        }

        return $counts;
    }

    /** @return list<string> */
    private function enumValues(string $enum): array
    {
        return array_map(static fn ($case): string => $case->value, $enum::cases());
    }
}
