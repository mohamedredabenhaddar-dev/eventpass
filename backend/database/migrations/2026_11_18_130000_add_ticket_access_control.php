<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->char('qr_token_hash', 64)->nullable()->unique();
            $table->string('access_state', 20)->default('NOT_ENTERED')->index();
        });

        Schema::create('event_staff_assignments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['event_id', 'staff_user_id']);
            $table->index(['staff_user_id', 'event_id']);
        });

        Schema::create('access_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('action', ['ENTRY', 'EXIT']);
            $table->string('gate', 120)->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();
            $table->index(['event_id', 'scanned_at']);
            $table->index(['ticket_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
        Schema::dropIfExists('event_staff_assignments');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['qr_token_hash']);
            $table->dropIndex(['access_state']);
            $table->dropColumn(['qr_token_hash', 'access_state']);
        });
    }
};
