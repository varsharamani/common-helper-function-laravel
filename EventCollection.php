<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\EventResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class EventCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request)
    {
        return [
            'data' => EventResource::collection($this),
            'today_event_count' => !empty($this->today_event_count) ? $this->today_event_count : 0,
            'open_count' => !empty($this->open_count) ? $this->open_count : 0,
            'close_count' => !empty($this->close_count) ? $this->close_count : 0,
            'cancel_count' => !empty($this->cancel_count) ? $this->cancel_count : 0,
            'meta' => [
                'current_page' => $this->currentPage(),
                'first_page_url' => $this->url(1),
                'from' => $this->firstItem(),
                'last_pages' => $this->lastPage(),
                'last_page_url' => $this->url($this->lastPage()),
                'next_page_url' => $this->nextPageUrl(),
                'path' => $this->path(),
                'per_page' => $this->perPage(),
                'prev_page_url' => $this->previousPageUrl(),
                'to' => $this->lastitem(),
                'total' => $this->total(),
            ]
        ];
    }
}
