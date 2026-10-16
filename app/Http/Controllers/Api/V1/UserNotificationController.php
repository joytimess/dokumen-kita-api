<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserNotificationResource;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserNotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();
        $query = $this->visibleNotifications($user);
        $page = $query->with('document')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return UserNotificationResource::collection($page)
            ->additional(['meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'unread_count' => $this->visibleNotifications($user)->whereNull('read_at')->count(),
            ]]);
    }

    public function read(Request $request, string $notification): UserNotificationResource
    {
        $user = $request->user();
        $item = $this->visibleNotifications($user)
            ->with('document')
            ->where('guid', $notification)
            ->firstOrFail();

        UserNotification::whereKey($item->getKey())
            ->where('user_guid', $user->guid)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        $item->refresh()->load('document');

        return (new UserNotificationResource($item))->additional(['meta' => [
            'unread_count' => $this->visibleNotifications($user)->whereNull('read_at')->count(),
        ]]);
    }

    /** @return Builder<UserNotification> */
    private function visibleNotifications(User $user): Builder
    {
        return UserNotification::query()
            ->where('user_guid', $user->guid)
            // Keep the owner's assignment.ready refresh event out of the inbox unless they are the signer.
            ->where(fn (Builder $query) => $query
                ->where('event_type', '!=', 'assignment.ready')
                ->orWhereHas('assignment', fn (Builder $assignment) => $assignment->where('user_guid', $user->guid)));
    }
}
