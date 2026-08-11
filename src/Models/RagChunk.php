<?php

namespace Awais\RagChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RagChunk extends Model
{
    protected $guarded = [];

    protected $casts = [
        'embedding' => 'array',
        'meta' => 'array',
        'position' => 'integer',
        'dimensions' => 'integer',
    ];

    public function getTable(): string
    {
        return config('rag-chat.database.chunks_table', 'rag_document_chunks');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(RagDocument::class, 'document_id');
    }
}
