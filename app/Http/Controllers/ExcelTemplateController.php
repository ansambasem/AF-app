<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveExcelTemplateRequest;
use App\Models\ExcelTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The company's Excel templates, read and saved from the Excel editor
 * while it is open, so they answer in JSON rather than reloading the page.
 */
class ExcelTemplateController extends Controller
{
    /**
     * A kind's templates, its default first, and whether the user may add or change them.
     */
    public function index(Request $request, string $kind): JsonResponse
    {
        validator(['kind' => $kind], ['kind' => [Rule::in(array_keys(ExcelTemplate::KINDS))]])->validate();

        return response()->json([
            'templates' => ExcelTemplate::query()->with('updatedBy')->where('kind', $kind)->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (ExcelTemplate $template): array => $template->toStudio())->values(),
            'canManage' => $request->user()->can('create', ExcelTemplate::class),
        ]);
    }

    public function store(SaveExcelTemplateRequest $request): JsonResponse
    {
        $template = ExcelTemplate::create([
            ...$request->safe()->only(['kind', 'name']),
            'settings' => $request->settings(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        if ($request->boolean('is_default')) {
            $template->makeDefault();
        }

        return response()->json(['template' => $template->refresh()->load('updatedBy')->toStudio()], 201);
    }

    /**
     * Rename it, save the editor's layout into it, or make it (or stop it being) its kind's default.
     */
    public function update(SaveExcelTemplateRequest $request, ExcelTemplate $excelTemplate): JsonResponse
    {
        $excelTemplate->update([
            ...$request->safe()->only(['name']),
            ...($request->has('settings') ? ['settings' => $request->settings()] : []),
            'updated_by' => $request->user()->id,
        ]);

        if ($request->has('is_default')) {
            $request->boolean('is_default') ? $excelTemplate->makeDefault() : $excelTemplate->update(['is_default' => false]);
        }

        return response()->json(['template' => $excelTemplate->refresh()->load('updatedBy')->toStudio()]);
    }

    public function destroy(ExcelTemplate $excelTemplate): JsonResponse
    {
        $this->authorize('delete', $excelTemplate);

        $excelTemplate->delete();

        return response()->json(['deleted' => true]);
    }
}
