<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['version_guid', 'pdf_hash', 'page_number', 'source', 'status', 'text', 'words', 'page_width', 'page_height', 'page_rotation', 'error_code', 'retryable', 'processed_at'])]
class DocumentOcrPage extends Model
{
    protected function casts(): array
    {
        return ['words' => 'array', 'retryable' => 'boolean', 'processed_at' => 'datetime'];
    }
}
