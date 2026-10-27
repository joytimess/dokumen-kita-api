<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Models\Document;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PersonalDocumentReadTools
{
    public const NAMES = ['count_my_documents', 'search_my_documents', 'get_my_document', 'get_document_signers', 'get_document_signing_progress', 'list_my_invitations', 'get_my_invitation'];

    private const VERSION_COLUMNS = ['document_versions.id', 'document_versions.guid', 'document_versions.document_guid', 'document_versions.version_number', 'document_versions.status', 'document_versions.published_at'];

    public function execute(User $user, string $tool, array $arguments): array
    {
        $common = ['query' => ['string', 'max:200'], 'status' => ['sometimes', 'required', 'string', Rule::in(array_column(VersionStatus::cases(), 'value'))], 'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:20'], 'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'], 'visibility' => ['sometimes', 'required', 'string', Rule::in(['active', 'hidden', 'all'])], 'exclude_completed' => ['sometimes', 'required', 'boolean']];
        $rules = match ($tool) {
            'count_my_documents' => array_intersect_key($common, array_flip(['status', 'visibility', 'exclude_completed'])),
            'search_my_documents' => $common,
            'get_my_document', 'get_document_signing_progress' => ['document_ref' => ['required', 'uuid'], 'page' => $common['page'], 'limit' => $common['limit']],
            'get_document_signers' => ['document_ref' => ['required', 'uuid'], 'page' => $common['page'], 'limit' => $common['limit'], 'signer_sequence' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'], 'signing_filter' => ['sometimes', 'required', 'string', Rule::in(['signed', 'unsigned'])]],
            'list_my_invitations' => ['query' => $common['query'], 'status' => ['sometimes', 'required', 'string', Rule::in(['waiting', 'ready', 'signed', 'revision_requested', 'rejected'])], 'page' => $common['page'], 'limit' => $common['limit']],
            'get_my_invitation' => ['assignment_ref' => ['required', 'uuid']],
            default => null,
        };
        if ($rules === null || array_diff(array_keys($arguments), array_keys($rules)) !== [] || Validator::make($arguments, $rules)->fails()) {
            return ['error' => 'invalid_tool'];
        }

        if (in_array($tool, ['list_my_invitations', 'get_my_invitation'], true)) {
            return $this->invitations($user, $arguments);
        }

        $query = Document::query()->where('owner_guid', $user->guid);
        $visibility = $arguments['visibility'] ?? 'active';
        if (! isset($arguments['document_ref'])) {
            $query->when($visibility === 'active', fn ($q) => $q->whereNull('hidden_at'))
                ->when($visibility === 'hidden', fn ($q) => $q->whereNotNull('hidden_at'))
                ->when(isset($arguments['status']), fn ($q) => $q->where('status', $arguments['status']))
                ->when($arguments['exclude_completed'] ?? false, fn ($q) => $q->where('status', '!=', VersionStatus::Completed->value));
            $this->search($query, $arguments['query'] ?? '');
        }
        if ($tool === 'count_my_documents') {
            return ['count' => $query->count(), 'filters_applied' => ['visibility' => $visibility, 'status' => $arguments['status'] ?? null, 'exclude_completed' => (bool) ($arguments['exclude_completed'] ?? false)], 'references' => []];
        }

        $query->select(['id', 'guid', 'owner_guid', 'category_guid', 'title', 'status', 'hidden_at', 'created_at', 'updated_at'])
            ->with(['category:guid,name', 'latestVersion' => fn ($q) => $q->select(self::VERSION_COLUMNS)]);
        if ($tool === 'search_my_documents') {
            $page = $query->orderByDesc('id')->simplePaginate($arguments['limit'] ?? 10, ['*'], 'page', $arguments['page'] ?? 1);
            $items = $page->getCollection()->filter(fn ($document) => Gate::forUser($user)->allows('manage', $document))->map($this->document(...))->values()->all();

            return ['items' => $items, 'pagination' => ['page' => $page->currentPage(), 'has_more' => $page->hasMorePages()], 'references' => array_column($items, 'reference')];
        }

        $document = $query->where('guid', $arguments['document_ref'])->first();
        if ($document === null || Gate::forUser($user)->denies('manage', $document)) {
            return ['error' => 'not_found'];
        }
        $result = $this->document($document);
        $result['references'] = [$result['reference']];
        if ($tool === 'get_my_document' || $document->latestVersion === null) {
            return $result;
        }

        $assignments = $document->latestVersion->assignments();
        if ($tool === 'get_document_signers') {
            $assignments->when(isset($arguments['signer_sequence']), fn ($q) => $q->where('sequence_number', $arguments['signer_sequence']));
            $assignments->when(($arguments['signing_filter'] ?? null) === 'signed', fn ($q) => $q->where('status', 'signed'))
                ->when(($arguments['signing_filter'] ?? null) === 'unsigned', fn ($q) => $q->where('status', '!=', 'signed'));
            $page = $assignments->with('user:guid,name')->simplePaginate($arguments['limit'] ?? 10, ['guid', 'version_guid', 'user_guid', 'sequence_number', 'status', 'recipient_email'], 'page', $arguments['page'] ?? 1);
            $result['signers'] = $page->map(fn ($a) => ['sequence' => $a->sequence_number, 'status' => $a->status, 'email' => $a->recipient_email, 'name' => $a->user?->name])->all();
            $result['pagination'] = ['page' => $page->currentPage(), 'has_more' => $page->hasMorePages()];
        } else {
            $counts = $assignments->reorder()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($count) => (int) $count)->all();
            $result['progress'] = ['total' => array_sum($counts), 'signed' => $counts['signed'] ?? 0, 'by_status' => $counts, 'active_sequence' => null, 'active_recipient' => null];
            // A ready row alone is not sufficient: the current workflow must also be in progress.
            if ($document->latestVersion->status === VersionStatus::InProgress) {
                $active = $document->latestVersion->assignments()->where('status', 'ready')->first(['sequence_number', 'recipient_email']);
                $result['progress']['active_sequence'] = $active?->sequence_number;
                $result['progress']['active_recipient'] = $active?->recipient_email;
            }
        }

        return $result;
    }

    private function search(Builder $query, string $search): void
    {
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern]);
        }
    }

    private function document(Document $document): array
    {
        return ['document_ref' => $document->guid, 'title' => $document->title, 'status' => $document->status->value,
            'category' => $document->category?->name, 'hidden' => $document->hidden_at !== null,
            'created_at' => $document->created_at?->toIso8601String(), 'updated_at' => $document->updated_at?->toIso8601String(),
            'version' => $document->latestVersion === null ? null : ['number' => $document->latestVersion->version_number, 'status' => $document->latestVersion->status->value, 'published_at' => $document->latestVersion->published_at?->toIso8601String()],
            'reference' => ['type' => 'document', 'resource_id' => $document->guid, 'action' => 'open_document', 'label' => $document->title]];
    }

    private function invitations(User $user, array $arguments): array
    {
        // Binding, rather than email matching or invitation tokens, is the existing access contract.
        $query = SignerAssignment::query()->where('user_guid', $user->guid)
            ->when(isset($arguments['assignment_ref']), fn ($q) => $q->where('guid', $arguments['assignment_ref']))
            ->when(isset($arguments['status']), fn ($q) => $q->where('status', $arguments['status']))
            ->when(isset($arguments['query']), function ($q) use ($arguments): void {
                $q->whereHas('version.document', function ($q) use ($arguments): void {
                    $this->search($q, $arguments['query']);
                });
            })
            ->with(['version' => fn ($q) => $q->select(self::VERSION_COLUMNS),
                'version.document:guid,owner_guid,title',
                'version.document.latestVersion' => fn ($q) => $q->select(self::VERSION_COLUMNS)]);
        $page = $query->orderByDesc('id')->simplePaginate($arguments['limit'] ?? 10, ['guid', 'version_guid', 'user_guid', 'sequence_number', 'status'], 'page', $arguments['page'] ?? 1);
        $items = [];
        $references = [];
        foreach ($page as $assignment) {
            if (Gate::forUser($user)->denies('act', $assignment)) {
                continue;
            }
            $version = $assignment->version;
            $current = $version->guid === $version->document->latestVersion?->guid;
            $eligible = $current && $user->hasVerifiedEmail() && $version->status === VersionStatus::InProgress && $assignment->status === 'ready';
            $reference = ['type' => 'assignment', 'resource_id' => $assignment->guid, 'document_id' => $version->document_guid, 'version_id' => $version->guid, 'action' => 'open_signing', 'label' => $version->document->title];
            // The viewer enforces viewPdf; waiting assignments cannot open it.
            if (in_array($assignment->status, ['ready', 'signed', 'revision_requested', 'rejected'], true)) {
                $references[] = $reference;
            } else {
                $reference = null;
            }
            $items[] = ['assignment_ref' => $assignment->guid, 'document_ref' => $version->document_guid, 'title' => $version->document->title,
                'version_number' => $version->version_number, 'version_status' => $version->status->value, 'current_version' => $current,
                'sequence' => $assignment->sequence_number, 'status' => $assignment->status, 'eligible_to_sign' => $eligible, 'reference' => $reference];
        }
        if (isset($arguments['assignment_ref']) && $items === []) {
            return ['error' => 'not_found'];
        }

        return ['items' => $items, 'pagination' => ['page' => $page->currentPage(), 'has_more' => $page->hasMorePages()], 'references' => $references];
    }
}
