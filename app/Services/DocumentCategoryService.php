<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DocumentCategoryService
{
    /** @param array{name: string, color_token: string, description?: string|null} $data */
    public function create(User $owner, array $data): DocumentCategory
    {
        return DocumentCategory::create($this->attributes($owner, $data));
    }

    /** @param array{name: string, color_token: string, description?: string|null} $data */
    public function update(User $owner, string $guid, array $data): DocumentCategory
    {
        $category = $this->findForOwner($owner, $guid, true);
        $category->update($this->attributes($owner, $data));

        return $category->refresh();
    }

    public function delete(User $owner, string $guid): int
    {
        return DB::transaction(function () use ($owner, $guid): int {
            $category = $this->findForOwner($owner, $guid, true);
            $count = $category->documents()->count();

            if ($count > 0) {
                return $count;
            }

            $category->delete();

            return 0;
        }, attempts: 3);
    }

    /** @param array<int, string> $documentGuids @return Collection<int, Document> */
    public function assign(User $owner, array $documentGuids, ?string $categoryGuid): Collection
    {
        return DB::transaction(function () use ($owner, $documentGuids, $categoryGuid): Collection {
            $category = $categoryGuid === null ? null : $this->findForOwner($owner, $categoryGuid, true);
            $documents = Document::where('owner_guid', $owner->guid)
                ->whereIn('guid', $documentGuids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($documents->count() !== count($documentGuids)) {
                abort(404);
            }

            foreach ($documents as $document) {
                Gate::authorize('manage', $document);
                $document->update(['category_guid' => $category?->guid]);
                $document->setRelation('category', $category);
            }

            return $documents;
        }, attempts: 3);
    }

    public function findForOwner(User $owner, string $guid, bool $lock = false): DocumentCategory
    {
        $query = DocumentCategory::where('owner_guid', $owner->guid)->where('guid', $guid);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @param array{name: string, color_token: string, description?: string|null} $data @return array<string, mixed> */
    private function attributes(User $owner, array $data): array
    {
        $name = trim($data['name']);

        return [
            'owner_guid' => $owner->guid,
            'name' => $name,
            'normalized_name' => mb_strtolower($name),
            'color_token' => $data['color_token'],
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
        ];
    }
}
