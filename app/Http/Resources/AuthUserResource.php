<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AuthUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role instanceof UserRole ? $this->role->value : (string) $this->role,
            'assignedSystems' => $this->whenLoaded(
                'systems',
                fn (): array => $this->systems->pluck('name')->values()->all(),
                [],
            ),
        ];
    }
}
