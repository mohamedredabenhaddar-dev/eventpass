<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateSuperAdminCommand extends Command
{
    protected $signature = 'app:create-super-admin';

    protected $description = 'Provision the initial EventPass Super Admin account';

    public function handle(): int
    {
        if ($this->superAdminExists()) {
            $this->error('A Super Admin account already exists. Initial provisioning is disabled.');

            return self::FAILURE;
        }

        $input = [
            'name' => $this->ask('Administrator name'),
            'email' => $this->ask('Administrator email'),
            'password' => $this->secret('Administrator password'),
            'password_confirmation' => $this->secret('Confirm administrator password'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()->symbols(), 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $lock = Cache::lock('eventpass:initial-super-admin-provisioning', 120);

        if (! $lock->get()) {
            $this->error('Super Admin provisioning is already in progress. Try again later.');

            return self::FAILURE;
        }

        try {
            try {
                DB::transaction(function () use ($validator): void {
                    if ($this->superAdminExists()) {
                        throw new \RuntimeException('A Super Admin account already exists. Initial provisioning is disabled.');
                    }

                    $user = User::create([
                        'name' => $validator->validated()['name'],
                        'email' => $validator->validated()['email'],
                        'password' => Hash::make($validator->validated()['password']),
                    ]);

                    $user->forceFill(['role' => UserRole::SuperAdmin])->save();
                });
            } catch (\RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            } catch (QueryException) {
                $this->error('The account could not be created. Confirm the email is unused and try again.');

                return self::FAILURE;
            }

            $this->info('The initial Super Admin account was created successfully.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function superAdminExists(): bool
    {
        return User::query()->where('role', UserRole::SuperAdmin->value)->exists();
    }
}
