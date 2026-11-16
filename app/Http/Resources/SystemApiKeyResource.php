<?php

namespace App\Http\Resources;

use App\Models\System;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin System
 */
class SystemApiKeyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'systemId' => $this->id,
            'systemName' => $this->name,
            'domain' => $this->domain,
            'apiKey' => $this->api_key,
        ];
    }
}
