<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffAssignedEventResource;
use Illuminate\Http\Request;

class StaffAssignedEventController extends Controller
{
    public function index(Request $request)
    {
        $events = $request->user()->assignedEvents()
            ->select([
                'events.id',
                'events.title',
                'events.slug',
                'events.description',
                'events.start_at',
                'events.end_at',
                'events.venue_name',
                'events.address',
                'events.city',
                'events.country',
                'events.status',
            ])
            ->withPivot(['id', 'assigned_by'])
            ->orderBy('events.start_at')
            ->paginate(15);

        return StaffAssignedEventResource::collection($events);
    }
}
