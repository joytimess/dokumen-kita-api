<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class UpdateSignersRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('version'));

        return true;
    }

    protected function prepareForValidation(): void
    {
        $signers = $this->input('signers');
        if (! is_array($signers)) {
            return;
        }

        foreach ($signers as &$signer) {
            if (is_array($signer) && isset($signer['email']) && is_string($signer['email'])) {
                $signer['email'] = Str::lower(trim($signer['email']));
            }
        }

        $this->merge(['signers' => $signers]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'signers' => ['present', 'array', 'list', 'max:'.config('documents.signing.max_signers')],
            'signers.*' => ['required', 'array:email,sequence_number'],
            'signers.*.email' => ['required', 'string', 'email:rfc', 'max:255', 'distinct'],
            'signers.*.sequence_number' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $sequence = array_map('intval', array_column($this->input('signers'), 'sequence_number'));
            sort($sequence);
            if ($sequence !== [] && $sequence !== range(1, count($sequence))) {
                $validator->errors()->add('signers', 'Urutan penerima harus 1 sampai N tanpa celah.');
            }
        }];
    }
}
