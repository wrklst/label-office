<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class ReleaseContractRequest extends LabelFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->nullify(['contract_id']);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'contract_id' => ['nullable', 'integer', $this->inLabel('contracts')],
            'replace' => ['boolean'],
        ];
    }
}
