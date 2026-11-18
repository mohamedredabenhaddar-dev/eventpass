<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\DemoPaymentService;
use Illuminate\Http\Request;

class ClientPaymentController extends Controller
{
    public function index(Request $request)
    {
        return PaymentResource::collection($request->user()->payments()
            ->with('reservation:id,reference,status,payment_status,payment_expires_at,event_id', 'reservation.event:id,status,start_at')
            ->latest()->paginate(15));
    }

    public function store(Request $request, Reservation $reservation, DemoPaymentService $payments)
    {
        return (new PaymentResource($payments->createAttempt($request->user(), $reservation)))->response()->setStatusCode(201);
    }

    public function show(Request $request, Payment $payment): PaymentResource
    {
        abort_unless($payment->reservation()->where('user_id', $request->user()->getKey())->exists(), 404);

        return new PaymentResource($payment->load('reservation:id,reference,status,payment_status,payment_expires_at,event_id', 'reservation.event:id,status,start_at'));
    }

    public function success(Request $request, Payment $payment, DemoPaymentService $payments): PaymentResource
    {
        return new PaymentResource($payments->simulate($request->user(), $payment, true)
            ->load('reservation.event:id,status,start_at'));
    }

    public function failure(Request $request, Payment $payment, DemoPaymentService $payments): PaymentResource
    {
        return new PaymentResource($payments->simulate($request->user(), $payment, false)
            ->load('reservation.event:id,status,start_at'));
    }
}
