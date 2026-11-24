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
        $this->nullify(['artist_payee_id']);
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
            'release_ids' => ['array'],
            'release_ids.*' => ['integer', 'distinct', $this->inLabel('releases')],
            'track_ids' => ['array'],
            // The tracks on offer are those of the chosen releases. One that is on two of them arrives twice.
            'track_ids.*' => ['integer', Rule::exists('release_track', 'track_id')->whereIn('release_id', array_filter((array) $this->input('release_ids'), 'is_numeric') ?: [0])],
            'terms' => ['array:'.implode(',', array_keys(Agreement::TERMS))],
            'terms.*' => ['nullable', 'string', 'max:5000'],
            'terms.share' => ['nullable', 'numeric', 'between:0,100'],
            'terms.publishing_share' => ['nullable', 'numeric', 'between:0,100'],
            // The label keeps what the shares do not hand out, so together they cannot pass 100.
            'terms.artist_share' => ['nullable', 'numeric', 'between:0,'.(100 - (is_numeric($this->input('terms.share')) ? min(100, max(0, (float) $this->input('terms.share'))) : 0))],
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
