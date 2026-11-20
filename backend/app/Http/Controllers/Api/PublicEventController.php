<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPublicEventsRequest;
use App\Http\Resources\PublicEventResource;
use App\Models\Event;

class PublicEventController extends Controller
{
    public function index(IndexPublicEventsRequest $request)
    {
        $filters = $request->validated();

        return PublicEventResource::collection(
            Event::query()->where('status', EventStatus::Published->value)
                ->where(function ($query) {
                    $query->where('end_at', '>=', now('UTC'))
                        ->orWhereNull('end_at');
                })
                ->when(! empty($filters['search'] ?? null), fn ($query) => $query->where(function ($q) use ($filters) {
                    $term = $filters['search'];
                    $q->where('title', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%")
                        ->orWhere('venue_name', 'like', "%{$term}%")
                        ->orWhere('city', 'like', "%{$term}%")
                        ->orWhere('country', 'like', "%{$term}%")
                        ->orWhere('category', 'like', "%{$term}%");
                }))
                ->when(! empty($filters['city'] ?? null), fn ($query) => $query->where('city', $filters['city']))
                ->when(! empty($filters['category'] ?? null), fn ($query) => $query->where('category', $filters['category']))
                ->orderBy('start_at')
                ->paginate($filters['per_page'] ?? 15),
        );
    }

    public function show(string $slug): PublicEventResource
    {
        $event = Event::query()
            ->where('status', EventStatus::Published->value)
            ->where('slug', $slug)
            ->firstOrFail();

        return new PublicEventResource($event);
    }
}
