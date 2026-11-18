<?php

use App\Enums\OrganizerRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('organization_name', 255);
            $table->text('description');
            $table->string('contact_email', 255);
            $table->string('contact_phone', 50);
            $table->string('website', 2048)->nullable();
            $table->enum('status', array_column(OrganizerRequestStatus::cases(), 'value'))
                ->default(OrganizerRequestStatus::Pending->value);
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_requests');
    }
};
