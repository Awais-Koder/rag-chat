<?php

namespace Awais\RagChat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:10000'],
        ];
    }

    public function message(): string
    {
        return (string) $this->input('message');
    }
}
