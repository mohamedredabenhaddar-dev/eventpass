<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizerProfileRequest;
use App\Models\OrganizerProfile;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizerProfileController extends Controller
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role->value !== 'organizer') {
            return response()->json(['message' => 'Organizer role required.'], 403);
        }

        $profile = $user->organizerProfile()->first();
        $defaults = ['organization_name' => $user->name, 'description' => '', 'contact_email' => '', 'contact_phone' => ''];

        return response()->json([
            'profile' => $profile ? $profile->only('organization_name', 'contact_email', 'contact_phone', 'description') : $defaults,
            'user' => $user->only('id', 'name', 'email'),
        ]);
    }

    public function update(UpdateOrganizerProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->organizerProfile()->first();
        if (! $profile) {
            $profile = new OrganizerProfile;
            $profile->user_id = $user->id;
        }
        $profile->fill($request->validated());
        $profile->save();

        $this->auditRecorder->recordUserAction($user, 'organizer_profile.updated', $profile->id);

        return response()->json(['message' => 'Profile updated.', 'profile' => $profile->only('organization_name', 'contact_email', 'contact_phone', 'description')]);
    }
}
