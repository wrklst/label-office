<?php

namespace App\Http\Requests;

use App\Models\AgreementTemplate;
use App\Support\AgreementExamples;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class AgreementTemplateRequest extends LabelFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // A new template is written by the label or copied from an example it takes responsibility for.
        if ($this->isMethod('POST') && $this->filled('example')) {
            return [
                'example' => [Rule::in(array_keys(AgreementExamples::all()))],
                'reviewed' => ['accepted'],
            ];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:200000', function (string $attribute, mixed $value, Closure $fail): void {
                if ($unknown = AgreementTemplate::unknownFields((string) $value)) {
                    $fail('The text uses fields that do not exist: '.implode(', ', $unknown).'.');
                }
            }],
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
            'reviewed.accepted' => 'Confirm that you take responsibility for this text before copying it.',
        ];
    }
}
