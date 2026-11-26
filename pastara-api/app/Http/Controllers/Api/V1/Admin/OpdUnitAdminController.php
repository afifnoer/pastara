<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OpdUnitIndexRequest;
use App\Http\Requests\Admin\StoreOpdUnitRequest;
use App\Http\Requests\Admin\UpdateOpdUnitRequest;
use App\Http\Resources\AdminOpdUnitResource;
use App\Models\OpdUnit;
use App\Models\User;
use App\Services\AdminManagementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class OpdUnitAdminController extends Controller
{
    public function __construct(
        private readonly AdminManagementService $adminService,
    ) {}

    public function index(OpdUnitIndexRequest $request): JsonResponse
    {
        $units = $this->adminService->listOpdUnits($request->validated());

        return ApiResponse::success(
            AdminOpdUnitResource::collection($units)->resolve($request)
        );
    }

    public function store(StoreOpdUnitRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $created = $this->adminService->createOpdUnit($actor, $request->validated());

        return ApiResponse::success(new AdminOpdUnitResource($created), null, 201);
    }

    public function update(UpdateOpdUnitRequest $request, OpdUnit $opdUnit): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $updated = $this->adminService->updateOpdUnit($actor, $opdUnit, $request->validated());

        return ApiResponse::success(new AdminOpdUnitResource($updated));
    }
}
