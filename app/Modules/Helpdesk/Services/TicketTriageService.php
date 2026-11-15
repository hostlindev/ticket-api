<?php

namespace App\Modules\Helpdesk\Services;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

class TicketTriageService
{
    /**
     * Classify whether the failure corresponds to frontend, backend, integracion, or por_definir.
     *
     * @param  array<string, mixed>  $telemetry
     */
    public function detectLayer(array $telemetry): string
    {
        $rawStatus = $telemetry['http_status'] ?? null;
        $status = $rawStatus !== null && $rawStatus !== '' ? (int) $rawStatus : null;
        $hasJsError = ! empty($telemetry['js_error']);

        if ($status !== null && $status >= 500) {
            return 'backend';
        }

        if (in_array($status, [400, 404, 405, 422], true)) {
            return 'integracion';
        }

        if ($hasJsError && ! $status) {
            return 'frontend';
        }

        if ($hasJsError && $status === 200) {
            return 'integracion';
        }

        return 'por_definir';
    }

    /**
     * Normalize dynamic route segments by replacing UUIDs and numeric IDs with placeholders.
     */
    public function normalizeRoute(string $rawPath): string
    {
        $clean = preg_replace(
            '/\/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/',
            '/:uuid',
            $rawPath,
        ) ?? $rawPath;

        return preg_replace('/\/\d+/', '/:id', $clean) ?? $clean;
    }

    /**
     * Generate a unique SHA-256 fingerprint to group identical incidents within the same module.
     *
     * @param  array<string, mixed>  $telemetry
     */
    public function generateFingerprint(string $systemId, string $normalizedRoute, string $errorType, array $telemetry): string
    {
        if (! empty($telemetry['failed_endpoint']) && ! empty($telemetry['http_status'])) {
            $cleanEndpoint = $this->normalizeRoute((string) $telemetry['failed_endpoint']);
            $signature = "NET|{$systemId}|{$normalizedRoute}|{$cleanEndpoint}|{$telemetry['http_status']}";
        } elseif (! empty($telemetry['js_error'])) {
            $cleanJsError = mb_substr(trim((string) $telemetry['js_error']), 0, 80);
            $signature = "JS|{$systemId}|{$normalizedRoute}|{$cleanJsError}";
        } else {
            $lastAction = (string) ($telemetry['last_ui_action'] ?? 'no-action');
            $signature = "UI|{$systemId}|{$normalizedRoute}|{$errorType}|{$lastAction}";
        }

        return hash('sha256', $signature);
    }

    /**
     * Find an active master ticket or one resolved within the last 48 hours sharing the fingerprint.
     */
    public function findExistingParent(string $systemId, string $fingerprint): ?Ticket
    {
        return Ticket::query()
            ->where('system_id', $systemId)
            ->where('fingerprint', $fingerprint)
            ->whereNull('parent_ticket_id')
            ->where(function (Builder $query): void {
                $query->whereIn('status', [
                    TicketStatus::Open->value,
                    TicketStatus::InProgress->value,
                    TicketStatus::InReview->value,
                ])->orWhere(function (Builder $resolvedQuery): void {
                    $resolvedQuery->where('status', TicketStatus::Resolved->value)
                        ->where('updated_at', '>=', now()->subHours(48));
                });
            })
            ->latest('id')
            ->first();
    }
}
