<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketCredentialService;
use Illuminate\Http\Request;

class ClientTicketCredentialController extends Controller
{
    public function store(Request $request, Ticket $ticket, TicketCredentialService $credentials)
    {
        return response()->json(['data' => $credentials->issueForOwner($request->user(), $ticket)], 201);
    }
}
