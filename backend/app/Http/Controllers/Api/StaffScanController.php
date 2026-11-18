<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccessAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScanTicketRequest;
use App\Services\TicketAccessService;

class StaffScanController extends Controller
{
    public function store(ScanTicketRequest $request, TicketAccessService $access)
    {
        $data = $request->validated();
        $result = $access->scan($request->user(), $data['qr_token'], AccessAction::from($data['action']), $data['gate'] ?? null);

        return response()->json(['message' => 'Ticket scan accepted.', 'data' => $result]);
    }
}
