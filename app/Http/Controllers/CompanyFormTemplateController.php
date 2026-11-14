<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCompanyFormTemplateRequest;
use App\Models\CompanyDocument;
use App\Models\CompanyFormTemplate;
use App\Support\CompanyForms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CompanyFormTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CompanyFormTemplate::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'view' => ['nullable', 'in:templates,documents'], 'per_page' => ['nullable', 'in:15,25,50,100']]);
        $search = trim($filters['search'] ?? '');
        $perPage = (int) ($filters['per_page'] ?? 15);
        $templates = CompanyFormTemplate::query()->with('updatedBy')
            ->when($search !== '', fn ($query) => $query->where(fn ($names) => $names->where('name', 'like', '%'.$search.'%')->orWhere('category', 'like', '%'.$search.'%')))
            ->latest('id')->paginate($perPage, ['*'], 'templates_page')->withQueryString()
            ->through(fn (CompanyFormTemplate $template): array => [
                'id' => $template->id, 'name' => $template->name, 'category' => $template->category,
                'version' => $template->version, 'imported' => $template->source_path !== null,
                'sourceName' => $template->source_name, 'updatedBy' => $template->updatedBy?->name,
                'updatedAt' => $template->updated_at->toIso8601String(),
            ]);
        $documents = CompanyDocument::query()->visibleTo($request->user())->with(['subscription', 'createdBy'])
            ->when($search !== '', fn ($query) => $query->where(fn ($names) => $names->where('title', 'like', '%'.$search.'%')->orWhere('paper_reference', 'like', '%'.$search.'%')))
            ->latest('id')->paginate($perPage, ['*'], 'documents_page')->withQueryString()
            ->through(fn (CompanyDocument $document): array => [
                'id' => $document->id, 'title' => $document->title, 'mode' => $document->mode,
                'subscriptionName' => $document->subscription?->displayName(),
                'paperReference' => $document->paper_reference, 'paperDate' => $document->paper_date?->toDateString(),
                'createdAt' => $document->created_at->toIso8601String(), 'createdBy' => $document->createdBy?->name,
            ]);

        return Inertia::render('CompanyForms/Index', [
            'templates' => $templates, 'documents' => $documents,
            'filters' => ['search' => $search, 'view' => $filters['view'] ?? 'templates', 'per_page' => $perPage],
            'canManage' => $request->user()->can('create', CompanyFormTemplate::class),
            'canIssue' => $request->user()->can('create', CompanyDocument::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', CompanyFormTemplate::class);

        return Inertia::render('CompanyForms/Editor', ['template' => null, 'defaultLayout' => CompanyForms::defaultLayout(), 'fields' => CompanyForms::FIELDS]);
    }

    public function edit(CompanyFormTemplate $companyForm): Response
    {
        $this->authorize('update', $companyForm);
        $companyForm->load('updatedBy');

        return Inertia::render('CompanyForms/Editor', ['template' => $companyForm->toEditor(), 'defaultLayout' => CompanyForms::defaultLayout(), 'fields' => CompanyForms::FIELDS]);
    }

    public function show(Request $request, CompanyFormTemplate $companyForm): JsonResponse
    {
        $this->authorize('view', $companyForm);

        return response()->json(['template' => $companyForm->toEditor(), 'fields' => CompanyForms::fields($companyForm->layout), 'values' => CompanyForms::context($request->user(), null)]);
    }

    public function store(SaveCompanyFormTemplateRequest $request): RedirectResponse
    {
        $source = $request->hasFile('source') ? CompanyForms::storeFile($request->file('source')) : null;
        try {
            $template = CompanyFormTemplate::create([
                ...$request->safe()->only(['name', 'category', 'layout']),
                'layout' => CompanyForms::normalizeLayout($request->validated('layout')),
                ...$this->sourceAttributes($source),
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
        } catch (Throwable $error) {
            if ($source) {
                Storage::disk($source['disk'])->delete($source['path']);
            }
            throw $error;
        }

        return to_route('company-forms.edit', $template)->with('status', 'company-form-saved');
    }

    public function update(SaveCompanyFormTemplateRequest $request, CompanyFormTemplate $companyForm): RedirectResponse
    {
        $source = null;
        try {
            DB::transaction(function () use ($request, $companyForm, &$source): void {
                $companyForm->refreshForUpdate();
                if ($companyForm->version !== (int) $request->validated('version')) {
                    throw ValidationException::withMessages(['version' => 'عدّل موظف آخر هذا النموذج. حدّث الصفحة قبل حفظ تغييراتك.']);
                }
                $source = $request->hasFile('source') ? CompanyForms::storeFile($request->file('source')) : null;
                $companyForm->update([
                    ...$request->safe()->only(['name', 'category', 'layout']),
                    'layout' => CompanyForms::normalizeLayout($request->validated('layout')),
                    ...$this->sourceAttributes($source),
                    'version' => $companyForm->version + 1, 'updated_by' => $request->user()->id,
                ]);
            });
        } catch (Throwable $error) {
            if ($source) {
                Storage::disk($source['disk'])->delete($source['path']);
            }
            throw $error;
        }

        return back()->with('status', 'company-form-saved');
    }

    public function destroy(CompanyFormTemplate $companyForm): RedirectResponse
    {
        $this->authorize('delete', $companyForm);
        $companyForm->delete();

        return to_route('company-forms.index')->with('status', 'company-form-archived');
    }

    public function source(Request $request, CompanyFormTemplate $companyForm): StreamedResponse
    {
        $this->authorize('view', $companyForm);
        abort_unless($companyForm->source_path && Storage::disk($companyForm->source_disk)->exists($companyForm->source_path), 404);

        return Storage::disk($companyForm->source_disk)->response($companyForm->source_path, $companyForm->source_name, ['Content-Type' => $companyForm->source_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'], $request->boolean('download') ? 'attachment' : 'inline');
    }

    /** @param array{disk: string, path: string, name: string, mime: string}|null $source
     * @return array<string, string>
     */
    private function sourceAttributes(?array $source): array
    {
        return $source === null ? [] : ['source_disk' => $source['disk'], 'source_path' => $source['path'], 'source_name' => $source['name'], 'source_mime' => $source['mime']];
    }
}
