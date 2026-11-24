<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\MarkNotificationsReadRequest;
use App\Http\Requests\Notification\NotificationIndexRequest;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function index(NotificationIndexRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $this->notificationService->listUserNotifications($user, $request->validated());
        $paginator = $result['paginator'];

        return ApiResponse::success(
            [
                'unread_count' => $result['unread_count'],
                'items' => NotificationResource::collection($paginator->getCollection())->resolve($request),
            ],
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    public function markAsRead(MarkNotificationsReadRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ids = $request->validated('notification_ids');

        return ApiResponse::success(
            $this->notificationService->markAsRead($user, is_array($ids) ? $ids : null)
        );
    }
}
