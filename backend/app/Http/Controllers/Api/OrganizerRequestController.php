<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrganizerRequestStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizerRequestRequest;
use App\Http\Resources\OrganizerRequestResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizerRequestController extends Controller
{
    public function store(StoreOrganizerRequestRequest $request): JsonResponse
    {
        $application = DB::transaction(function () use ($request) {
            $user = User::query()
                ->whereKey($request->user()->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($user->role === UserRole::Client, 403, 'Only clients may apply to become organizers.');

            $hasPending = $user->organizerRequests()
                ->where('status', OrganizerRequestStatus::Pending->value)
                ->exists();

            abort_unless(! $hasPending, 409, 'A pending organizer application already exists.');

            return $user->organizerRequests()->create($request->validated());
        });

        $application->refresh()->load(['user:id,name,email', 'reviewer:id,name']);

        return (new OrganizerRequestResource($application))->response()->setStatusCode(201);
    }

    public function mine(Request $request)
    {
        $applications = $request->user()->organizerRequests()
            ->with(['user:id,name,email', 'reviewer:id,name'])
            ->latest()
            ->paginate(15);

        return OrganizerRequestResource::collection($applications);
    }
}
