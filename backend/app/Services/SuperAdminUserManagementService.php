<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SuperAdminUserManagementService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function suspend(User $actor, User $target, string $reason): User
    {
        return DB::transaction(function () use ($actor, $target, $reason) {
            $activeAdmins = $this->lockActiveSuperAdmins();
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            abort_unless($lockedActor->role === UserRole::SuperAdmin && $lockedActor->account_status === AccountStatus::Active, 401, 'Unauthenticated.');

            abort_if($lockedActor->is($target), 409, 'A Super Admin cannot suspend their own account.');

            $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->getKey());
            abort_if($lockedTarget->account_status === AccountStatus::Suspended, 409, 'This account is already suspended.');

            $previousStatus = $lockedTarget->account_status->value;
            $targetRole = $lockedTarget->role->value;

            if ($lockedTarget->role === UserRole::SuperAdmin) {
                abort_if($activeAdmins->count() <= 1, 409, 'The last active Super Admin cannot be suspended.');
            }

            $lockedTarget->forceFill([
                'account_status' => AccountStatus::Suspended,
                'suspended_at' => now(),
                'suspended_by' => $lockedActor->getKey(),
                'suspension_reason' => $reason,
            ])->save();

            // Revoke existing credentials in addition to centrally rejecting suspended accounts.
            $lockedTarget->tokens()->delete();

            $this->audit->recordUserAction($lockedActor, 'user.suspended', $lockedTarget->getKey(), metadata: [
                'previous_status' => $previousStatus,
                'new_status' => AccountStatus::Suspended->value,
                'target_role' => $targetRole,
            ]);

            return $lockedTarget->refresh();
        }, attempts: 3);
    }

    public function reactivate(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target) {
            $this->lockActiveSuperAdmins();
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            abort_unless($lockedActor->role === UserRole::SuperAdmin && $lockedActor->account_status === AccountStatus::Active, 401, 'Unauthenticated.');

            $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->getKey());
            abort_if($lockedTarget->account_status === AccountStatus::Active, 409, 'This account is already active.');

            $previousStatus = $lockedTarget->account_status->value;
            $targetRole = $lockedTarget->role->value;

            $lockedTarget->forceFill([
                'account_status' => AccountStatus::Active,
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
            ])->save();

            $this->audit->recordUserAction($lockedActor, 'user.reactivated', $lockedTarget->getKey(), metadata: [
                'previous_status' => $previousStatus,
                'new_status' => AccountStatus::Active->value,
                'target_role' => $targetRole,
            ]);

            return $lockedTarget->refresh();
        }, attempts: 3);
    }

    private function lockActiveSuperAdmins()
    {
        return User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->where('account_status', AccountStatus::Active->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'role', 'account_status']);
    }
}
