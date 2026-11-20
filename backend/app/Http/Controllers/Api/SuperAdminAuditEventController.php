<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexSuperAdminAuditEventsRequest;
use App\Http\Resources\AuditEventResource;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;

class SuperAdminAuditEventController extends Controller
{
    public function index(IndexSuperAdminAuditEventsRequest $request)
    {
        $filters = $request->validated();
        $query = AuditEvent::query();

        foreach (['action', 'actor_type', 'entity_type', 'outcome'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (isset($filters['actor_id'])) {
            $query->where('actor_type', 'user')->where('actor_id', $filters['actor_id']);
        }

        if (isset($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }

        if (isset($filters['date_from'])) {
            $query->where('occurred_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_from'], 'UTC'));
        }

        if (isset($filters['date_to'])) {
            $exclusiveEnd = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'], 'UTC')->addDay();
            $query->where('occurred_at', '<', $exclusiveEnd);
        }

        $paginator = $query->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return AuditEventResource::collection($paginator);
    }
}
