<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessLogResource;
use App\Models\Event;
use App\Services\TicketAccessService;
use Illuminate\Http\Request;

class EventAccessReportController extends Controller
{
    public function organizer(Request $request, Event $event, TicketAccessService $access)
    {
        abort_unless($event->organizer_id === $request->user()->getKey(), 404);

        return $this->report($event, $access);
    }

    public function staff(Request $request, Event $event, TicketAccessService $access)
    {
        abort_unless($event->staffAssignments()->where('staff_user_id', $request->user()->getKey())->exists(), 403, 'You are not assigned to this event.');

        return $this->report($event, $access);
    }

    public function superAdmin(Event $event, TicketAccessService $access)
    {
        return $this->report($event, $access);
    }

    private function report(Event $event, TicketAccessService $access)
    {
        $history = $event->accessLogs()->with(['ticket:id,reference', 'staff:id,name'])->latest('scanned_at')->paginate(30);

        return response()->json([
            'data' => [
                'statistics' => $access->statistics($event),
                'history' => AccessLogResource::collection($history)->response()->getData(true),
            ],
        ]);
    }
}
