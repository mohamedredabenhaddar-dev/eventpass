<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientPaymentController;
use App\Http\Controllers\Api\ClientReservationController;
use App\Http\Controllers\Api\ClientTicketCredentialController;
use App\Http\Controllers\Api\EventAccessReportController;
use App\Http\Controllers\Api\OrganizerEventController;
use App\Http\Controllers\Api\OrganizerEventStaffController;
use App\Http\Controllers\Api\OrganizerRequestController;
use App\Http\Controllers\Api\OrganizerReservationController;
use App\Http\Controllers\Api\OrganizerTicketTypeController;
use App\Http\Controllers\Api\PublicEventController;
use App\Http\Controllers\Api\PublicTicketTypeController;
use App\Http\Controllers\Api\StaffAssignedEventController;
use App\Http\Controllers\Api\StaffProvisioningController;
use App\Http\Controllers\Api\StaffScanController;
use App\Http\Controllers\Api\SuperAdminAuditEventController;
use App\Http\Controllers\Api\SuperAdminDashboardStatisticsController;
use App\Http\Controllers\Api\SuperAdminEventController;
use App\Http\Controllers\Api\SuperAdminEventStaffController;
use App\Http\Controllers\Api\SuperAdminOrganizerRequestController;
use App\Http\Controllers\Api\SuperAdminReservationController;
use App\Http\Controllers\Api\SuperAdminTicketTypeController;
use App\Http\Controllers\Api\SuperAdminUserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:registration');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::get('/events', [PublicEventController::class, 'index']);
Route::get('/events/{event}/ticket-types', [PublicTicketTypeController::class, 'index'])->whereNumber('event');
Route::get('/events/{slug}', [PublicEventController::class, 'show']);

Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/super-admin/user', fn (Request $request) => $request->user())
        ->middleware('role:'.UserRole::SuperAdmin->value);
    Route::get('/organizer/user', fn (Request $request) => $request->user())
        ->middleware('role:'.UserRole::Organizer->value);
    Route::get('/staff/user', fn (Request $request) => $request->user())
        ->middleware('role:'.UserRole::Staff->value);
    Route::get('/client/user', fn (Request $request) => $request->user())
        ->middleware('role:'.UserRole::Client->value);

    Route::middleware('role:'.UserRole::Client->value)->group(function () {
        Route::post('/organizer-requests', [OrganizerRequestController::class, 'store']);
        Route::get('/organizer-requests/my', [OrganizerRequestController::class, 'mine']);
        Route::get('/reservations', [ClientReservationController::class, 'index']);
        Route::get('/payments', [ClientPaymentController::class, 'index']);
        Route::get('/payments/{payment}', [ClientPaymentController::class, 'show'])->whereNumber('payment');
        Route::post('/reservations/{reservation}/payments', [ClientPaymentController::class, 'store'])->whereNumber('reservation')->middleware('throttle:payments');
        Route::post('/payments/{payment}/simulate-success', [ClientPaymentController::class, 'success'])->whereNumber('payment')->middleware('throttle:payments');
        Route::post('/payments/{payment}/simulate-failure', [ClientPaymentController::class, 'failure'])->whereNumber('payment')->middleware('throttle:payments');
        Route::get('/reservations/{reservation}', [ClientReservationController::class, 'show'])->whereNumber('reservation');
        Route::patch('/reservations/{reservation}/cancel', [ClientReservationController::class, 'cancel'])->whereNumber('reservation');
        Route::post('/events/{event}/reservations', [ClientReservationController::class, 'store'])->whereNumber('event');
        Route::post('/tickets/{ticket}/qr-credential', [ClientTicketCredentialController::class, 'store'])
            ->whereNumber('ticket')->middleware('throttle:qr-credentials');
    });

    Route::middleware('role:'.UserRole::Staff->value)->prefix('staff')->group(function () {
        Route::get('/events', [StaffAssignedEventController::class, 'index']);
        Route::post('/scans', [StaffScanController::class, 'store'])->middleware('throttle:staff-scans');
        Route::get('/events/{event}/access', [EventAccessReportController::class, 'staff'])->whereNumber('event');
    });

    Route::prefix('super-admin')->middleware('role:'.UserRole::SuperAdmin->value)->group(function () {
        Route::get('/audit-events', [SuperAdminAuditEventController::class, 'index']);
        Route::get('/dashboard/statistics', [SuperAdminDashboardStatisticsController::class, 'show']);
        Route::get('/users', [SuperAdminUserController::class, 'index']);
        Route::get('/users/{user}', [SuperAdminUserController::class, 'show'])->whereNumber('user');
        Route::patch('/users/{user}/suspend', [SuperAdminUserController::class, 'suspend'])->whereNumber('user');
        Route::patch('/users/{user}/reactivate', [SuperAdminUserController::class, 'reactivate'])->whereNumber('user');
        Route::post('/staff/provision', [StaffProvisioningController::class, 'provision']);

        Route::get('/organizer-requests', [SuperAdminOrganizerRequestController::class, 'index']);
        Route::get('/organizer-requests/{id}', [SuperAdminOrganizerRequestController::class, 'show'])
            ->whereNumber('id');
        Route::patch('/organizer-requests/{id}/approve', [SuperAdminOrganizerRequestController::class, 'approve'])
            ->whereNumber('id');
        Route::patch('/organizer-requests/{id}/reject', [SuperAdminOrganizerRequestController::class, 'reject'])
            ->whereNumber('id');

        Route::get('/events', [SuperAdminEventController::class, 'index']);
        Route::get('/events/{event}', [SuperAdminEventController::class, 'show'])->whereNumber('event');
        Route::patch('/events/{event}/suspend', [SuperAdminEventController::class, 'suspend'])->whereNumber('event');
        Route::patch('/events/{event}/reactivate', [SuperAdminEventController::class, 'reactivate'])->whereNumber('event');
        Route::patch('/events/{event}/cancel', [SuperAdminEventController::class, 'cancel'])->whereNumber('event');
        Route::get('/events/{event}/staff', [SuperAdminEventStaffController::class, 'index'])->whereNumber('event');
        Route::get('/events/{event}/access', [EventAccessReportController::class, 'superAdmin'])->whereNumber('event');

        Route::get('/ticket-types', [SuperAdminTicketTypeController::class, 'index']);
        Route::get('/ticket-types/{ticketType}', [SuperAdminTicketTypeController::class, 'show'])->whereNumber('ticketType');
        Route::get('/reservations', [SuperAdminReservationController::class, 'index']);
        Route::get('/reservations/{reservation}', [SuperAdminReservationController::class, 'show'])->whereNumber('reservation');
    });

    Route::prefix('organizer')->middleware('role:'.UserRole::Organizer->value)->group(function () {
        Route::get('/events/{event}/ticket-types', [OrganizerTicketTypeController::class, 'index'])->whereNumber('event');
        Route::post('/events/{event}/ticket-types', [OrganizerTicketTypeController::class, 'store'])->whereNumber('event');
        Route::get('/events/{event}/ticket-types/{ticketType}', [OrganizerTicketTypeController::class, 'show'])->whereNumber('event')->whereNumber('ticketType');
        Route::patch('/events/{event}/ticket-types/{ticketType}', [OrganizerTicketTypeController::class, 'update'])->whereNumber('event')->whereNumber('ticketType');
        Route::delete('/events/{event}/ticket-types/{ticketType}', [OrganizerTicketTypeController::class, 'destroy'])->whereNumber('event')->whereNumber('ticketType');
        Route::get('/events/{event}/reservations', [OrganizerReservationController::class, 'index'])->whereNumber('event');
        Route::get('/events/{event}/reservations/{reservation}', [OrganizerReservationController::class, 'show'])->whereNumber('event')->whereNumber('reservation');
        Route::get('/events/{event}/staff', [OrganizerEventStaffController::class, 'index'])->whereNumber('event');
        Route::post('/events/{event}/staff', [OrganizerEventStaffController::class, 'store'])->whereNumber('event');
        Route::delete('/events/{event}/staff/{assignment}', [OrganizerEventStaffController::class, 'destroy'])->whereNumber('event')->whereNumber('assignment');
        Route::get('/events/{event}/access', [EventAccessReportController::class, 'organizer'])->whereNumber('event');
        Route::get('/events', [OrganizerEventController::class, 'index']);
        Route::post('/events', [OrganizerEventController::class, 'store']);
        Route::get('/events/{event}', [OrganizerEventController::class, 'show'])->whereNumber('event');
        Route::match(['put', 'patch'], '/events/{event}', [OrganizerEventController::class, 'update'])
            ->whereNumber('event');
        Route::patch('/events/{event}/publish', [OrganizerEventController::class, 'publish'])->whereNumber('event');
        Route::patch('/events/{event}/unpublish', [OrganizerEventController::class, 'unpublish'])->whereNumber('event');
        Route::patch('/events/{event}/cancel', [OrganizerEventController::class, 'cancel'])->whereNumber('event');
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'account.active']);
