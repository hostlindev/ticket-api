<?php

namespace App\Http\Resources;

use App\Enums\SlaStatus;
use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserIncidentResource extends JsonResource
{
    /**
     * Transform the user incident into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'systemName' => $this->systemName,
            'systemCode' => $this->systemCode,
            'severity' => $this->severity instanceof TicketSeverity ? $this->severity->value : $this->severity,
            'status' => $this->status instanceof TicketStatus ? $this->status->value : $this->status,
            'slaTime' => $this->slaTime,
            'slaStatus' => $this->slaStatus instanceof SlaStatus ? $this->slaStatus->value : $this->slaStatus,
            'createdAt' => $this->createdAt,
            'assignedToMe' => (bool) $this->assignedToMe,
        ];
    }
}
