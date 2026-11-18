<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('available_quantity');
            $table->dateTime('sales_start_at')->nullable();
            $table->dateTime('sales_end_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['event_id', 'is_active']);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->decimal('total', 18, 2);
            $table->enum('status', ['confirmed', 'cancelled'])->default('confirmed');
            $table->enum('payment_status', ['unpaid', 'paid', 'refunded'])->default('unpaid');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['event_id', 'created_at']);
        });

        Schema::create('reservation_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('subtotal', 18, 2);
            $table->timestamps();

            $table->unique(['reservation_id', 'ticket_type_id']);
            $table->index('ticket_type_id');
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->enum('status', ['valid', 'cancelled'])->default('valid');
            $table->timestamps();

            $table->index(['reservation_id', 'status']);
            $table->index(['ticket_type_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('reservation_items');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('ticket_types');
    }
};
