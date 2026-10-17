<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentVersion;
use App\Services\DocumentOcrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentOcrController extends Controller
{
    public function show(Request $request, DocumentVersion $version, DocumentOcrService $ocr)
    {
        Gate::authorize('viewPdf', $version);
        $data = $request->validate([
            'expected_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/i'],
            'query' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $ocr->status($version, $data['expected_hash'], trim($data['query'] ?? ''))]);
    }

    public function store(Request $request, DocumentVersion $version, DocumentOcrService $ocr)
    {
        Gate::authorize('viewPdf', $version);
        $data = $request->validate(['expected_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/i']]);

        return response()->json(['data' => $ocr->start($version, $data['expected_hash'])], 202);
    }

    public function retry(Request $request, DocumentVersion $version, DocumentOcrService $ocr)
    {
        Gate::authorize('viewPdf', $version);
        $data = $request->validate([
            'expected_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/i'],
            'pages' => ['required', 'array', 'min:1', 'max:'.(int) config('documents.ocr.max_pages')],
            'pages.*' => ['required', 'integer', 'min:1', 'max:200', 'distinct'],
        ]);

        return response()->json(['data' => $ocr->retry($version, $data['expected_hash'], $data['pages'])], 202);
    }
}
