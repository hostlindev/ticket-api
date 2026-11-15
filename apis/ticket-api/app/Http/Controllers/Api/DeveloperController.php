<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeveloperResource;
use App\Services\UserService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeveloperController extends Controller
{
    public function __construct(private readonly UserService $userService) {}

    /**
     * List all team members available for system assignment.
     */
    public function index(): AnonymousResourceCollection
    {
        return DeveloperResource::collection($this->userService->listDevelopers());
    }
}
