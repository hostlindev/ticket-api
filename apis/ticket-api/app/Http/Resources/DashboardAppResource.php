<?php

namespace App\Http\Resources;

use App\Enums\AppHealth;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardAppResource extends JsonResource
{
    /**
     * Transform the dashboard app summary into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'domain' => $this->domain,
            'lead' => $this->lead instanceof User ? $this->lead->name : $this->lead,
            'health' => $this->health instanceof AppHealth ? $this->health->value : $this->health,
            'uptime' => number_format((float) $this->uptime, 2, '.', '').'%',
            'severity' => $this->severity,
            'statuses' => $this->statuses,
            'created' => $this->created,
            'resolved' => $this->resolved,
        ];
    }
}
