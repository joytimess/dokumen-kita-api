<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiChatResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reply' => $this->resource['reply'],
            'locale' => $this->resource['locale'],
            'suggested_questions' => $this->resource['suggested_questions'],
            'references' => $this->resource['references'] ?? [],
            'intent' => $this->resource['intent'] ?? 'support',
            'results' => $this->resource['results'] ?? null,
            'context' => $this->resource['context'] ?? null,
            'show_references' => $this->resource['show_references'] ?? true,
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->header('Cache-Control', 'no-store, private');
    }
}
