<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\AdminCategoryResource;
use App\Models\Category;
use App\Models\User;
use App\Services\AdminManagementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryAdminController extends Controller
{
    public function __construct(
        private readonly AdminManagementService $adminService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $categories = $this->adminService->listCategories();

        return ApiResponse::success(
            AdminCategoryResource::collection($categories)->resolve($request)
        );
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $updated = $this->adminService->updateCategory($actor, $category, $request->validated());

        return ApiResponse::success(new AdminCategoryResource($updated));
    }
}
