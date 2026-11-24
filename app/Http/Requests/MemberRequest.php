<?php

namespace App\Http\Requests;

use App\Enums\LabelRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class MemberRequest extends LabelFormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->nullify(['payee_id', 'name']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [$this->isMethod('POST') ? 'required' : 'exclude', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::enum(LabelRole::class)],
            'payee_id' => ['nullable', 'required_if:role,payee', 'integer', $this->inLabel('payees')],
        ];
    }
}
