<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNotIn('role', UserRole::values())->update(['role' => UserRole::Client->value]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default(UserRole::Client->value)->change();
        });
    }

    public function down(): void
    {
        DB::table('users')->whereIn('role', UserRole::values())->update(['role' => 'user']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->change();
        });
    }
};
