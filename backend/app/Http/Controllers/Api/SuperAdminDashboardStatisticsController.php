<?php

namespace App\Http\Controllers\Api;

use App\Services\SuperAdminDashboardStatisticsService;
use Illuminate\Http\JsonResponse;

class SuperAdminDashboardStatisticsController
{
    public function show(SuperAdminDashboardStatisticsService $statistics): JsonResponse
    {
        return response()->json(['data' => $statistics->snapshot()]);
    }
}
