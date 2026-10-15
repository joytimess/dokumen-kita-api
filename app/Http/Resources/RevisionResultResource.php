<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class RevisionResultResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'previous_version_guid' => $this->resource['previous']->guid,
            'previous_status' => $this->resource['previous']->status,
            'draft_version_guid' => $this->resource['draft']->guid,
            'draft_status' => $this->resource['draft']->status,
        ];
    }
}
