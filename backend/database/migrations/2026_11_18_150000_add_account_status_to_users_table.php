<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_status', 20)->default('active')->index();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('suspension_reason')->nullable();
            $table->index(['role', 'account_status', 'id'], 'users_role_account_status_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_account_status_id_index');
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropIndex(['account_status']);
            $table->dropColumn(['account_status', 'suspended_at', 'suspension_reason']);
        });
    }
};
