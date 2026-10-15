<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Document\UpdateVersionDecorationRequest;
use App\Http\Resources\DocumentVersionResource;
use App\Models\DocumentVersion;
use App\Services\VersionDecorationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VersionDecorationController extends Controller
{
    public function update(UpdateVersionDecorationRequest $request, DocumentVersion $version, string $kind, VersionDecorationService $decorations): DocumentVersionResource
    {
        Gate::authorize('update', $version);

        return new DocumentVersionResource($decorations->update(
            $request->user(), $version, $kind, $request->integer('editor_revision'),
            $request->file('file'), $request->validated('pages'),
        ));
    }

    public function destroy(Request $request, DocumentVersion $version, string $kind, VersionDecorationService $decorations): DocumentVersionResource
    {
        Gate::authorize('update', $version);
        $validated = $request->validate(['editor_revision' => ['required', 'integer', 'min:1']]);

        return new DocumentVersionResource($decorations->delete($request->user(), $version, $kind, (int) $validated['editor_revision']));
    }

    public function image(DocumentVersion $version, string $kind): StreamedResponse
    {
        Gate::authorize('update', $version);
        $decoration = $version->{$kind};
        if ($decoration === null) {
            abort(404);
        }
        if (! Storage::disk(config('documents.disk'))->exists($decoration['path'])) {
            throw ApiException::conflict('DECORATION_IMAGE_UNAVAILABLE', 'Gambar kop atau footer tidak tersedia.');
        }

        return Storage::disk(config('documents.disk'))->response($decoration['path'], $kind.'.png', [
            'Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
