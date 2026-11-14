<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePrintTemplateRequest;
use App\Models\PrintTemplate;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The company's print templates: listed together on their own page, by
 * list, and saved from the print designer. Designing one happens in the
 * designer itself, opened on the list's real rows.
 */
class PrintTemplateController extends Controller
{
    /**
     * Every template, under the list it prints.
     */
    public function index(): InertiaResponse
    {
        $this->authorize('viewAny', PrintTemplate::class);

        $templates = PrintTemplate::query()->with('updatedBy')->orderByDesc('is_default')->orderBy('name')->get()->groupBy('page');

        return Inertia::render('Settings/PrintTemplates', [
            'pages' => collect(PrintTemplate::PAGES)
                ->map(fn (array $page, string $path) => [
                    'path' => $path,
                    'label' => $page['label'],
                    'icon' => $page['icon'],
                    'templates' => ($templates[$path] ?? collect())->map(fn (PrintTemplate $template) => [
                        ...$template->toDesigner(),
                        'columnCount' => collect($template->layout['columns'] ?? [])->where('visible', true)->count(),
                        'paper' => $template->layout['paper'] ?? 'A4',
                        'orientation' => $template->layout['orientation'] ?? 'portrait',
                    ])->values(),
                ])
                ->values(),
        ]);
    }

    public function store(SavePrintTemplateRequest $request): RedirectResponse
    {
        $template = PrintTemplate::create([
            ...$request->safe()->only(['page', 'name']),
            'layout' => $request->layout(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        if ($request->boolean('is_default')) {
            $template->makeDefault();
        }

        $request->user()->notify(new ActionCompleted('print-template-created', $template->name));

        return back()->with('status', 'print-template-created');
    }

    /**
     * Rename it, save a new design into it, or make it (or stop it being)
     * its list's default.
     */
    public function update(SavePrintTemplateRequest $request, PrintTemplate $printTemplate): RedirectResponse
    {
        $printTemplate->update([
            ...$request->safe()->only(['name']),
            ...($request->has('layout') ? ['layout' => $request->layout()] : []),
            'updated_by' => $request->user()->id,
        ]);

        if ($request->has('is_default')) {
            $request->boolean('is_default') ? $printTemplate->makeDefault() : $printTemplate->update(['is_default' => false]);
        }

        $request->user()->notify(new ActionCompleted('print-template-updated', $printTemplate->name));

        return back()->with('status', 'print-template-updated');
    }

    /**
     * A copy under the next free name, to change without touching the original.
     */
    public function duplicate(Request $request, PrintTemplate $printTemplate): RedirectResponse
    {
        $this->authorize('create', PrintTemplate::class);

        $copy = $printTemplate->replicate(['is_default']);
        $copy->fill(['name' => $printTemplate->copyName(), 'is_default' => false, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id])->save();

        $request->user()->notify(new ActionCompleted('print-template-created', $copy->name));

        return back()->with('status', 'print-template-created');
    }

    public function destroy(Request $request, PrintTemplate $printTemplate): RedirectResponse
    {
        $this->authorize('delete', $printTemplate);

        $printTemplate->delete();
        $request->user()->notify(new ActionCompleted('print-template-deleted', $printTemplate->name));

        return back()->with('status', 'print-template-deleted');
    }
}
