<?php

namespace App\Http\Resources\Flutter;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'full_name' => (string) $this->full_name,
            'phone'  => $this->phone ? (string) $this->phone : null];
    }
}
