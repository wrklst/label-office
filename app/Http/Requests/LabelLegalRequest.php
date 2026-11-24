<?php

namespace App\Http\Requests;

use App\Enums\LabelRole;
use App\Models\Label;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class LabelLegalRequest extends LabelFormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->nullify(['legal_name', 'legal_form', 'legal_address', 'legal_register']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $admin = Rule::exists('label_user', 'user_id')->where('label_id', $this->label()->id)->where('role', LabelRole::Admin->value);

        return [
            'legal_name' => ['nullable', 'string', 'max:255'],
            'legal_form' => ['nullable', Rule::in(Label::LEGAL_FORMS)],
            'legal_address' => ['nullable', 'string', 'max:1000'],
            'legal_register' => ['nullable', 'string', 'max:255'],
            'signatories' => ['array'],
            'signatories.*' => ['integer', $admin],
            'self_dealing_exempt' => ['array'],
            'self_dealing_exempt.*' => ['integer', $admin],
        ];
    }
}
