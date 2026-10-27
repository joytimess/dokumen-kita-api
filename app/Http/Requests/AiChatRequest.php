<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AiChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000', 'regex:/[^\s\p{Z}]/u'],
            'locale' => ['sometimes', 'string', Rule::in(['id', 'en'])],
            'context' => ['sometimes', 'nullable', 'string', 'max:12000'],
            'history' => ['sometimes', 'array', 'list', 'max:6'],
            'history.*' => ['required', 'array:role,content'],
            'history.*.role' => ['required', 'string', Rule::in(['user', 'assistant'])],
            'history.*.content' => ['required', 'string', 'max:1000', 'regex:/[^\s\p{Z}]/u'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->input('message'))]);
        }
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $payload = json_decode($this->getContent());
            if (! $this->isJson() || ! is_object($payload) || $this->allFiles() !== []
                || array_diff(array_keys($this->all()), ['message', 'locale', 'history', 'context']) !== []) {
                $validator->errors()->add('payload', 'Only message, locale, history and context are accepted as a JSON object; files are not supported.');
            }

            if (is_object($payload) && property_exists($payload, 'history') && ! is_array($payload->history)) {
                $validator->errors()->add('history', 'History must be a list.');
            }
        }];
    }
}
