<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class ManagedUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'specialty' => $this->specialty,
            'systemsCount' => $this->systems_count ?? $this->systems()->count(),
            'resolvedCount' => $this->resolved_tickets_count ?? $this->resolvedTickets()->count(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
