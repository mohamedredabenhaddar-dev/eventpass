<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketPdfController extends Controller
{
    public function __construct(private TicketPdfService $pdfService) {}

    public function download(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        if ($ticket->status->value !== 'valid') {
            return response()->json(['message' => 'This ticket cannot be downloaded.'], 422);
        }

        $event = $ticket->reservation?->event;
        if (! $event) {
            return response()->json(['message' => 'This ticket is not linked to an event.'], 422);
        }

        $isOwner = $user->role->value === 'client' && $ticket->reservation?->user_id === $user->id;
        $isStaff = $user->role->value === 'staff';

        if (! $isOwner && ! $isStaff) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $pdfContent = $this->pdfService->generate($event, $ticket);

        return response()->json([], 200, [
            'Content-Type' => 'application/pdf',
        ])->setContent($pdfContent);
    }
}
