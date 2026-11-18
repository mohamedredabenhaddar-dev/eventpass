<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventStaffAssignmentResource;
use App\Models\Event;

class SuperAdminEventStaffController extends Controller
{
    public function index(Event $event)
    {
        return EventStaffAssignmentResource::collection($event->staffAssignments()->with('staff:id,name,email')->orderBy('id')->paginate(20));
    }
}
