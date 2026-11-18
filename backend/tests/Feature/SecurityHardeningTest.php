<?php

namespace Tests\Feature;

use App\Console\Commands\CreateSuperAdminCommand;
use App\Enums\AccessAction;
use App\Enums\EventStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketAccessState;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\TicketCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_super_admin_command_prompts_for_and_hashes_valid_credentials(): void
    {
        $this->artisan('app:create-super-admin')
            ->expectsQuestion('Administrator name', 'Ada Admin')
            ->expectsQuestion('Administrator email', 'ada-admin@example.test')
            ->expectsQuestion('Administrator password', 'Strong-pass1!')
            ->expectsQuestion('Confirm administrator password', 'Strong-pass1!')
            ->expectsOutputToContain('created successfully')
            ->assertExitCode(0);

        $admin = User::where('email', 'ada-admin@example.test')->firstOrFail();
        $this->assertSame(UserRole::SuperAdmin, $admin->role);
        $this->assertTrue(Hash::check('Strong-pass1!', $admin->password));
        $this->assertNotSame('Strong-pass1!', $admin->password);
    }

    public function test_initial_super_admin_command_rejects_an_existing_email(): void
    {
        User::create(['name' => 'Existing User', 'email' => 'existing@example.test', 'password' => 'secret-password']);

        $this->artisan('app:create-super-admin')
            ->expectsQuestion('Administrator name', 'Another Admin')
            ->expectsQuestion('Administrator email', 'existing@example.test')
            ->expectsQuestion('Administrator password', 'Strong-pass1!')
            ->expectsQuestion('Confirm administrator password', 'Strong-pass1!')
            ->expectsOutputToContain('email has already been taken')
            ->assertExitCode(1);

        $this->assertSame(0, User::where('role', UserRole::SuperAdmin->value)->count());
    }

    public function test_initial_super_admin_command_refuses_a_second_super_admin(): void
    {
        $this->makeUser(UserRole::SuperAdmin, 'existing-admin@example.test');

        $this->artisan('app:create-super-admin')
            ->expectsOutputToContain('A Super Admin account already exists')
            ->assertExitCode(1);

        $this->assertSame(1, User::where('role', UserRole::SuperAdmin->value)->count());
    }

    public function test_initial_super_admin_command_rejects_a_weak_password(): void
    {
        $this->artisan('app:create-super-admin')
            ->expectsQuestion('Administrator name', 'Weak Admin')
            ->expectsQuestion('Administrator email', 'weak-admin@example.test')
            ->expectsQuestion('Administrator password', 'password')
            ->expectsQuestion('Confirm administrator password', 'password')
            ->expectsOutputToContain('password')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'weak-admin@example.test']);
    }

    public function test_public_registration_cannot_assign_super_admin(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Public Applicant',
            'email' => 'public-applicant@example.test',
            'password' => 'Strong-pass1!',
            'password_confirmation' => 'Strong-pass1!',
            'role' => UserRole::SuperAdmin->value,
        ])->assertCreated()->assertJsonPath('user.role', UserRole::Client->value);

        $this->assertDatabaseHas('users', [
            'email' => 'public-applicant@example.test',
            'role' => UserRole::Client->value,
        ]);
    }

    public function test_seeders_do_not_provision_super_admins_and_command_has_no_password_option(): void
    {
        foreach (glob(database_path('seeders/*.php')) ?: [] as $seederPath) {
            $this->assertStringNotContainsString(
                UserRole::SuperAdmin->value,
                file_get_contents($seederPath),
                'Seeders must not provision administrator accounts.'
            );
        }

        $command = $this->app->make(CreateSuperAdminCommand::class);
        $this->assertSame([], $command->getDefinition()->getArguments());
        $this->assertArrayNotHasKey('password', $command->getDefinition()->getOptions());
    }

    public function test_login_limit_returns_429_after_allowing_requests_within_the_limit(): void
    {
        config(['rate_limits.login_per_minute' => 2]);
        User::create(['name' => 'Login User', 'email' => 'login-user@example.test', 'password' => 'Strong-pass1!']);

        $this->postJson('/api/login', ['email' => 'login-user@example.test', 'password' => 'Strong-pass1!'])->assertOk();
        $this->postJson('/api/login', ['email' => 'login-user@example.test', 'password' => 'wrong-password'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'login-user@example.test', 'password' => 'wrong-password'])
            ->assertTooManyRequests()->assertExactJson(['message' => 'Too many requests.']);
    }

    public function test_registration_limit_allows_normal_registration_then_returns_429(): void
    {
        config(['rate_limits.registration_per_hour' => 1]);

        $this->postJson('/api/register', $this->registrationData('first@example.test'))->assertCreated();
        $this->postJson('/api/register', $this->registrationData('second@example.test'))
            ->assertTooManyRequests()->assertExactJson(['message' => 'Too many requests.']);
    }

    public function test_qr_credential_limit_allows_one_issue_then_returns_429(): void
    {
        config(['rate_limits.qr_credentials_per_minute' => 1]);
        [$client, $ticket] = $this->makeTicketFixture();

        $this->actingAs($client, 'sanctum')->postJson("/api/tickets/{$ticket->id}/qr-credential")->assertCreated();
        $this->postJson("/api/tickets/{$ticket->id}/qr-credential")
            ->assertTooManyRequests()->assertExactJson(['message' => 'Too many requests.']);
    }

    public function test_staff_scan_limit_allows_a_scan_then_returns_429(): void
    {
        config(['rate_limits.staff_scans_per_minute' => 1]);
        [$staff, $ticket, $token] = $this->makeTicketFixture(withStaff: true);

        $this->actingAs($staff, 'sanctum')->postJson('/api/staff/scans', [
            'qr_token' => $token,
            'action' => AccessAction::Entry->value,
        ])->assertOk();

        $this->postJson('/api/staff/scans', [
            'qr_token' => $token,
            'action' => AccessAction::Exit->value,
        ])->assertTooManyRequests()->assertExactJson(['message' => 'Too many requests.']);
    }

    private function registrationData(string $email): array
    {
        return [
            'name' => 'Rate Limited User',
            'email' => $email,
            'password' => 'Strong-pass1!',
            'password_confirmation' => 'Strong-pass1!',
        ];
    }

    private function makeTicketFixture(bool $withStaff = false): array
    {
        $organizer = $this->makeUser(UserRole::Organizer, 'organizer@example.test');
        $client = $this->makeUser(UserRole::Client, 'ticket-client@example.test');
        $staff = $withStaff ? $this->makeUser(UserRole::Staff, 'ticket-staff@example.test') : null;
        $event = new Event([
            'title' => 'Rate Limit Event', 'description' => 'Test event', 'category' => 'Music',
            'venue_name' => 'Test Hall', 'address' => '1 Main Street', 'city' => 'Casablanca',
            'country' => 'Morocco', 'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHours(2),
            'capacity' => 20,
        ]);
        $event->slug = 'rate-limit-event';
        $event->status = EventStatus::Published;
        $organizer->events()->save($event);

        $type = new TicketType(['name' => 'General', 'price' => '10.00', 'quantity' => 5, 'is_active' => true]);
        $type->available_quantity = 4;
        $event->ticketTypes()->save($type);

        $reservation = new Reservation([
            'reference' => 'EP-RATE-LIMIT-1', 'total' => '10.00',
            'status' => ReservationStatus::Confirmed, 'payment_status' => 'paid',
        ]);
        $reservation->user()->associate($client);
        $reservation->event()->associate($event);
        $reservation->save();

        $item = new ReservationItem(['quantity' => 1, 'unit_price' => '10.00', 'subtotal' => '10.00']);
        $item->ticketType()->associate($type);
        $reservation->items()->save($item);

        $token = TicketCredentialService::generateToken();
        $ticket = Ticket::forceCreate([
            'reservation_id' => $reservation->id,
            'reservation_item_id' => $item->id,
            'ticket_type_id' => $type->id,
            'reference' => 'TKT-RATE-LIMIT-1',
            'status' => TicketStatus::Valid,
            'qr_token_hash' => hash('sha256', $token),
            'access_state' => TicketAccessState::NotEntered,
        ]);

        if ($staff) {
            EventStaffAssignment::create([
                'event_id' => $event->id,
                'staff_user_id' => $staff->id,
                'assigned_by' => $organizer->id,
            ]);

            return [$staff, $ticket, $token];
        }

        return [$client, $ticket];
    }

    private function makeUser(UserRole $role, string $email): User
    {
        $user = User::create(['name' => ucfirst($role->value), 'email' => $email, 'password' => 'secret-password']);
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }
}
