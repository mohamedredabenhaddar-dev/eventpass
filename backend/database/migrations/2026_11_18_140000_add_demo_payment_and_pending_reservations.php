<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->enum('status', ['pending_payment', 'confirmed', 'cancelled', 'expired'])
                ->default('pending_payment')->change();
            $table->enum('payment_status', ['unpaid', 'pending', 'paid', 'failed', 'refunded', 'expired'])
                ->default('unpaid')->change();
            $table->timestamp('payment_expires_at')->nullable()->index();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->enum('status', ['pending', 'valid', 'cancelled'])->default('pending')->change();
            $table->char('qr_token_hash', 64)->nullable()->change();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $table->string('payment_reference', 48)->unique();
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3)->default('MAD');
            $table->enum('status', ['pending', 'succeeded', 'failed', 'expired'])->default('pending');
            $table->string('payment_method', 40)->default('demo');
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['reservation_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        $legacyReservations = DB::table('reservations')
            ->where('status', 'confirmed')
            ->where('payment_status', 'unpaid');
        DB::table('tickets')->whereIn('reservation_id', (clone $legacyReservations)->select('id'))
            ->where('status', 'valid')->update(['status' => 'pending', 'qr_token_hash' => null]);
        $legacyReservations->update([
            'status' => 'pending_payment',
            'payment_expires_at' => now()->addMinutes((int) config('ticketing.reservation_expiration_minutes', 15)),
        ]);
    }

    public function down(): void
    {
        DB::table('reservations')->whereIn('status', ['pending_payment', 'expired'])->update(['status' => 'cancelled']);
        DB::table('reservations')->whereIn('payment_status', ['pending', 'failed', 'expired'])->update(['payment_status' => 'unpaid']);
        DB::table('tickets')->where('status', 'pending')->update(['status' => 'cancelled']);
        Schema::dropIfExists('payments');

        Schema::table('tickets', function (Blueprint $table) {
            $table->enum('status', ['valid', 'cancelled'])->default('valid')->change();
            $table->char('qr_token_hash', 64)->nullable()->change();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['payment_expires_at']);
            $table->dropColumn('payment_expires_at');
            $table->enum('payment_status', ['unpaid', 'paid', 'refunded'])->default('unpaid')->change();
            $table->enum('status', ['confirmed', 'cancelled'])->default('confirmed')->change();
        });
    }
};
