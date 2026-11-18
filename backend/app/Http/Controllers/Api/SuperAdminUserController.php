<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexSuperAdminUsersRequest;
use App\Http\Requests\SuspendUserRequest;
use App\Http\Resources\SuperAdminUserResource;
use App\Models\User;
use App\Services\SuperAdminUserManagementService;
use Illuminate\Http\Request;

class SuperAdminUserController extends Controller
{
    public function index(IndexSuperAdminUsersRequest $request)
    {
        $filters = $request->validated();
        $query = User::query();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($users) use ($search) {
                $users->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (isset($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (isset($filters['account_status'])) {
            $query->where('account_status', $filters['account_status']);
        }

        return SuperAdminUserResource::collection(
            $query->orderByDesc('id')->paginate($filters['per_page'] ?? 15),
        );
    }

    public function show(User $user): SuperAdminUserResource
    {
        return new SuperAdminUserResource($user);
    }

    public function suspend(
        SuspendUserRequest $request,
        User $user,
        SuperAdminUserManagementService $accounts,
    ): SuperAdminUserResource {
        return new SuperAdminUserResource(
            $accounts->suspend($request->user(), $user, $request->validated('suspension_reason')),
        );
    }

    public function reactivate(
        Request $request,
        User $user,
        SuperAdminUserManagementService $accounts,
    ): SuperAdminUserResource {
        return new SuperAdminUserResource($accounts->reactivate($request->user(), $user));
    }
}
