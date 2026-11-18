<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountStatus;
use App\Enums\OrganizerRequestStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectOrganizerRequestRequest;
use App\Http\Resources\OrganizerRequestResource;
use App\Models\OrganizerRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminOrganizerRequestController extends Controller
{
    public function index()
    {
        $applications = OrganizerRequest::query()
            ->with(['user:id,name,email', 'reviewer:id,name'])
            ->latest()
            ->paginate(15);

        return OrganizerRequestResource::collection($applications);
    }

    public function show(int $id): OrganizerRequestResource
    {
        $application = OrganizerRequest::with(['user:id,name,email', 'reviewer:id,name'])->findOrFail($id);

        return new OrganizerRequestResource($application);
    }

    public function approve(Request $request, int $id): OrganizerRequestResource
    {
        $application = DB::transaction(function () use ($request, $id) {
            $application = OrganizerRequest::query()->lockForUpdate()->findOrFail($id);
            abort_unless($application->status === OrganizerRequestStatus::Pending, 409, 'This application has already been reviewed.');

            $user = $application->user()->lockForUpdate()->firstOrFail();
            abort_unless($user->role === UserRole::Client, 409, 'The applicant is no longer a client.');
            abort_unless($user->account_status === AccountStatus::Active, 409, 'Suspended applicants cannot be approved.');

            $application->status = OrganizerRequestStatus::Approved;
            $application->reviewed_by = $request->user()->getKey();
            $application->reviewed_at = now();
            $application->rejection_reason = null;
            $application->save();

            $user->role = UserRole::Organizer;
            $user->save();

            return $application;
        });

        return new OrganizerRequestResource($application->load(['user:id,name,email', 'reviewer:id,name']));
    }

    public function reject(RejectOrganizerRequestRequest $request, int $id): OrganizerRequestResource
    {
        $application = DB::transaction(function () use ($request, $id) {
            $application = OrganizerRequest::query()->lockForUpdate()->findOrFail($id);
            abort_unless($application->status === OrganizerRequestStatus::Pending, 409, 'This application has already been reviewed.');

            $application->status = OrganizerRequestStatus::Rejected;
            $application->reviewed_by = $request->user()->getKey();
            $application->reviewed_at = now();
            $application->rejection_reason = $request->validated('rejection_reason');
            $application->save();

            return $application;
        });

        return new OrganizerRequestResource($application->load(['user:id,name,email', 'reviewer:id,name']));
    }
}
