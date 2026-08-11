<?php

namespace Awais\RagChat\Http\Controllers;

use Awais\RagChat\Http\Requests\IngestRequest;
use Awais\RagChat\Models\RagDocument;
use Awais\RagChat\RagChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class DocumentController
{
    public function __invoke(IngestRequest $request, RagChat $rag): JsonResponse
    {
        if ($request->hasFile('file')) {
            $document = $this->ingestUpload($request, $rag);
        } else {
            $document = $rag->ingestText(
                text: (string) $request->input('text'),
                source: $request->input('source') ?? 'api:text',
                title: $request->input('title'),
            );
        }

        return response()->json([
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'source' => $document->source,
                'chunks' => $document->chunks_count ?? $document->chunks()->count(),
            ],
        ], 201);
    }

    /**
     * Persist the upload to a temp path that preserves the original extension,
     * then ingest through LoaderManager so PDF/MD/TXT all share one path.
     */
    protected function ingestUpload(IngestRequest $request, RagChat $rag): RagDocument
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'txt');

        $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rag-upload-'.uniqid('', true).'.'.$extension;
        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_file($realPath) || ! copy($realPath, $tempPath)) {
            throw new RuntimeException('Unable to stage the uploaded file for ingestion.');
        }

        try {
            return $rag->ingest($tempPath, [
                'title' => $request->input('title') ?? $originalName,
                'source' => $request->input('source') ?? $originalName,
                'uploaded_filename' => $originalName,
            ]);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
