<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class ApiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => $request->attributes->get('request_id')]];
    }
}
