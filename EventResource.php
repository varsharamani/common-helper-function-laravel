<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Models\ClientSuccessManager;
use Illuminate\Support\Facades\Storage;
use App\Http\Resources\PositionResource;
use Illuminate\Http\Resources\Json\JsonResource;


class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "user_id" => $this->user_id,
            'user_name' => $this->user ? $this->user->full_name : null,
            "name" => $this->name,
            "overview" => $this->overview,
            "location" => $this->location,
            "image" => $this->image  !== null ? get_document_link($this->image) : null,
            "from_date" => $this->from_date,
            "to_date" => $this->to_date,
            "status" => $this->status,
            "client_success_manager" => $this->csm_id ? ClientSuccessManager::where('id', $this->csm_id)->first() : null,
            "prioritize_favourite" => $this->prioritize_favourite,
            "quantity_count" => !empty($this->quantity_count) ? $this->quantity_count : null,
            "hire_count" => $this->hire_count ? $this->hire_count : null,
            "positions" => PositionResource::collection($this->positions),
            "created_at" => date('Y-m-d', strtotime($this->created_at)),
            "updated_at" => date('Y-m-d', strtotime($this->updated_at)),
        ];
    }
}
