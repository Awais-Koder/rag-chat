<?php

namespace Awais\RagChat\Http\Requests;

use Awais\RagChat\Rag\LoaderManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $extensions = implode(',', $this->acceptedExtensions());
        $maxKb = (int) config('rag-chat.ingestion.max_upload_kb', 5120);

        return [
            // Either a raw text payload OR an uploaded file must be provided.
            'text' => ['required_without:file', 'nullable', 'string', 'min:1'],
            'file' => ['required_without:text', 'nullable', 'file', "mimes:{$extensions}", "max:{$maxKb}"],
            'title' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:1024'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (blank($this->input('text')) && ! $this->hasFile('file')) {
                $validator->errors()->add('text', 'Provide either a text payload or an uploaded file.');
            }
        });
    }

    /**
     * Prefer registered loaders as the source of truth; fall back to config.
     *
     * @return string[]
     */
    protected function acceptedExtensions(): array
    {
        if (app()->bound(LoaderManager::class)) {
            return app(LoaderManager::class)->supportedExtensions();
        }

        return config('rag-chat.ingestion.extensions', ['txt', 'md', 'markdown', 'pdf']);
    }
}
