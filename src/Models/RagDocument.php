<?php

namespace Awais\RagChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RagDocument extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    public function getTable(): string
    {
        return config('rag-chat.database.documents_table', 'rag_documents');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(RagChunk::class, 'document_id');
    }
}
