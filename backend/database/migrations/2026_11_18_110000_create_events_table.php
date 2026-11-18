<?php

use App\Enums\EventStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('slug', 255)->unique();
            $table->longText('description');
            $table->string('category', 100);
            $table->string('venue_name', 255);
            $table->string('address', 512);
            $table->string('city', 150);
            $table->string('country', 150);
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedInteger('capacity');
            $table->string('cover_image', 2048)->nullable();
            $table->enum('status', array_column(EventStatus::cases(), 'value'))->default(EventStatus::Draft->value);
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'start_at']);
            $table->index(['organizer_id', 'start_at']);
            $table->index(['city', 'country']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
