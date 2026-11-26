<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Services\AdminManagementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AuditLogAdminController extends Controller
{
    public function __construct(
        private readonly AdminManagementService $adminService,
    ) {}

    public function index(AuditLogIndexRequest $request): JsonResponse
    {
        $paginator = $this->adminService->listAuditLogs($request->validated());

        return ApiResponse::success(
            AuditLogResource::collection($paginator->getCollection())->resolve($request),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }
}
