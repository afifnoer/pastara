<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLocationRequest;
use App\Http\Requests\Admin\UpdateLocationRequest;
use App\Models\User;
use App\Services\AdminManagementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class LocationAdminController extends Controller
{
    public function __construct(
        private readonly AdminManagementService $adminService,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->adminService->listLocations());
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $created = $this->adminService->createLocation($actor, $request->validated());

        return ApiResponse::success($created, null, 201);
    }

    public function update(UpdateLocationRequest $request, string $type, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $updated = $this->adminService->updateLocation($actor, $type, $id, $request->validated());

        return ApiResponse::success($updated);
    }
}
