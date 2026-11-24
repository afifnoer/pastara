<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UserIndexRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AdminManagementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UserAdminController extends Controller
{
    public function __construct(
        private readonly AdminManagementService $adminService,
    ) {}

    public function index(UserIndexRequest $request): JsonResponse
    {
        $paginator = $this->adminService->listUsers($request->validated());

        return ApiResponse::success(
            UserResource::collection($paginator->getCollection())->resolve($request),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $created = $this->adminService->createUser($actor, $request->validated());

        return ApiResponse::success(new UserResource($created), null, 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $updated = $this->adminService->updateUser($actor, $user, $request->validated());

        return ApiResponse::success(new UserResource($updated));
    }
}
