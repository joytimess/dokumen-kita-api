<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEventResource;
use App\Http\Resources\DocumentVersionResource;
use App\Http\Resources\EmailNotificationResource;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\EmailNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DocumentActivityController extends Controller
{
    public function audit(Request $request, Document $document): AnonymousResourceCollection
    {
        Gate::authorize('view', $document);
        $query = AuditEvent::where('document_guid', $document->guid);
        if ($document->owner_guid !== $request->user()->guid) {
            $query->whereIn('version_guid', DocumentVersion::where('document_guid', $document->guid)
                ->whereHas('assignments', fn ($assignments) => $assignments->where('user_guid', $request->user()->guid))
                ->select('guid'));
        }

        return AuditEventResource::collection($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function versions(Request $request, Document $document): AnonymousResourceCollection
    {
        Gate::authorize('view', $document);
        $query = DocumentVersion::where('document_guid', $document->guid)->with('document.latestVersion:guid,document_guid,version_number', 'assignments.placeholders', 'assignments.readConfirmation', 'assignments.signature');
        if ($document->owner_guid !== $request->user()->guid) {
            $query->whereHas('assignments', fn ($assignments) => $assignments->where('user_guid', $request->user()->guid));
        }

        return DocumentVersionResource::collection($query->orderByDesc('version_number')->paginate($this->perPage($request)));
    }

    public function emails(Request $request, Document $document): AnonymousResourceCollection
    {
        Gate::authorize('manage', $document);

        return EmailNotificationResource::collection(EmailNotification::whereIn(
            'version_guid', $document->versions()->select('guid')
        )->orderByDesc('id')->paginate($this->perPage($request)));
    }

    private function perPage(Request $request): int
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return (int) ($validated['per_page'] ?? 25);
    }
}
