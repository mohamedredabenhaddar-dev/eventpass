<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('actor_type', 16);
            // Intentionally no foreign key: historical actor IDs must survive account deletion.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_role', 32)->nullable();
            $table->string('action', 64);
            // Intentionally no foreign key: entity history must survive entity deletion.
            $table->string('entity_type', 48);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('outcome', 16);
            $table->json('metadata')->nullable();

            $table->index(['occurred_at', 'id'], 'audit_events_time_id_index');
            $table->index(['action', 'occurred_at'], 'audit_events_action_time_index');
            $table->index(['actor_type', 'actor_id', 'occurred_at'], 'audit_events_actor_time_index');
            $table->index(['entity_type', 'entity_id', 'occurred_at'], 'audit_events_entity_time_index');
            $table->index(['outcome', 'occurred_at'], 'audit_events_outcome_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
