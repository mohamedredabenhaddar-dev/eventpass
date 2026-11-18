<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('organizer_requests')
            ->select('user_id')
            ->where('status', 'pending')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot enforce one pending organizer request while duplicate pending rows exist for user IDs: '
                .$duplicates->implode(', '),
            );
        }

        Schema::table('organizer_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('pending_user_id')
                ->nullable()
                ->storedAs("CASE WHEN status = 'pending' THEN user_id ELSE NULL END");
            $table->unique('pending_user_id', 'organizer_requests_one_pending_per_user');
        });
    }

    public function down(): void
    {
        Schema::table('organizer_requests', function (Blueprint $table) {
            $table->dropUnique('organizer_requests_one_pending_per_user');
            $table->dropColumn('pending_user_id');
        });
    }
};
