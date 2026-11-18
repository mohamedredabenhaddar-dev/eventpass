<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicEventResource;
use App\Models\Event;

class PublicEventController extends Controller
{
    public function index()
    {
        return PublicEventResource::collection(
            Event::query()->where('status', EventStatus::Published->value)
                ->orderBy('start_at')
                ->paginate(15),
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
