<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SigningHistoryRequest;
use App\Http\Resources\SigningHistoryResource;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SigningHistoryController extends Controller
{
    public function index(SigningHistoryRequest $request): AnonymousResourceCollection
    {
        $query = AuditEvent::query()
            ->where('actor_guid', $request->user()->guid)
            ->whereIn('action', ['assignment.signed', 'version.revision_requested'])
            ->with(['document.owner', 'version']);

        if ($search = $request->validated('q')) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->whereHas('document', fn ($documents) => $documents->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern]));
        }

        if ($date = $request->validated('date_from')) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $date, 'Asia/Jakarta')->startOfDay()->utc());
        }

        if ($date = $request->validated('date_to')) {
            $query->where('created_at', '<', CarbonImmutable::createFromFormat('Y-m-d', $date, 'Asia/Jakarta')->addDay()->startOfDay()->utc());
        }

        return SigningHistoryResource::collection($query->orderByDesc('created_at')->orderByDesc('id')->paginate($request->validated('per_page', 25)));
    }

    public function show(SigningHistoryRequest $request, AuditEvent $event): SigningHistoryResource
    {
        abort_unless(
            $event->actor_guid === $request->user()->guid
                && in_array($event->action, ['assignment.signed', 'version.revision_requested'], true),
            404,
        );

        return new SigningHistoryResource($event->load(['document.owner', 'version']));
    }
}
