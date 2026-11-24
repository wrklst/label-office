<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgreementTemplateRequest;
use App\Models\AgreementTemplate;
use App\Support\AgreementExamples;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AgreementTemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('agreement-templates/index', [
            'templates' => AgreementTemplate::query()->orderBy('name')->get()->map(fn (AgreementTemplate $template): array => [
                ...$template->only(['id', 'name', 'body']),
                'updated_at' => $template->updated_at?->toDateString(),
            ]),
            'examples' => array_values(AgreementExamples::all()),
            'notice' => AgreementExamples::NOTICE,
            'fields' => AgreementTemplate::FIELDS,
        ]);
    }

    public function store(AgreementTemplateRequest $request): RedirectResponse
    {
        $example = AgreementExamples::all()[$request->validated('example')] ?? null;

        AgreementTemplate::query()->create($example ? ['name' => $example['name'], 'body' => $example['body']] : $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => $example ? __('Copied to your templates. Have it reviewed before you use it.') : __('Template created.')]);

        return back();
    }

    public function update(AgreementTemplateRequest $request, AgreementTemplate $agreementTemplate): RedirectResponse
    {
        $agreementTemplate->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved.')]);

        return back();
    }

    public function destroy(AgreementTemplate $agreementTemplate): RedirectResponse
    {
        $agreementTemplate->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template deleted.')]);

        return back();
    }
}
