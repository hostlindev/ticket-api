<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardAppResource;
use App\Http\Resources\UserIncidentResource;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    /**
     * Return the aggregated dashboard snapshot for the authenticated team member.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $snapshot = $this->dashboardService->snapshot($user);

        return response()->json([
            'data' => [
                'apps' => DashboardAppResource::collection($snapshot['apps']),
                'userIncidents' => UserIncidentResource::collection($snapshot['userIncidents']),
                'mttrHours' => $snapshot['mttrHours'],
                'slaCompliance' => $snapshot['slaCompliance'],
                'resolvedSprint' => $snapshot['resolvedSprint'],
            ],
        ]);
    }
}
