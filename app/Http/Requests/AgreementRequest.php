<?php

namespace App\Http\Requests;

use App\Models\Agreement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class AgreementRequest extends LabelFormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->nullify(['artist_payee_id', 'release_id', 'track_id']);

        if ($this->input('licensor') !== 'artist') {
            $this->merge(['artist_payee_id' => null]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'agreement_template_id' => ['required', 'integer', $this->inLabel('agreement_templates')],
            'licensor' => ['required', Rule::in(['label', 'artist'])],
            'payee_id' => ['required', 'integer', $this->inLabel('payees')],
            'artist_payee_id' => ['nullable', 'required_if:licensor,artist', 'integer', 'different:payee_id', $this->inLabel('payees')],
            'release_id' => ['nullable', 'integer', $this->inLabel('releases')],
            'track_id' => ['nullable', 'integer', $this->inLabel('tracks')],
            'terms' => ['array:'.implode(',', array_keys(Agreement::TERMS))],
            'terms.*' => ['nullable', 'string', 'max:5000'],
            'terms.share' => ['nullable', 'numeric', 'between:0,100'],
            'terms.publishing_share' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'artist_payee_id.required_if' => 'Choose the artist who grants the rights.',
            'artist_payee_id.different' => 'The artist and the payee have to be two different payees.',
        ];
    }
}
