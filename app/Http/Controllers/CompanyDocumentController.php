<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyDocumentRequest;
use App\Models\CompanyDocument;
use App\Models\CompanyFormTemplate;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CompanyForms;
use Illuminate\Database\UniqueConstraintViolationException;
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

class CompanyDocumentController extends Controller
{
    public function subscriptions(Request $request): JsonResponse
    {
        $this->authorize('create', CompanyDocument::class);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'id' => ['nullable', 'integer']]);
        $query = Subscription::query()->visibleTo($request->user());
        if (! empty($data['id'])) {
            $subscription = $query->findOrFail($data['id']);

            return response()->json(['values' => CompanyForms::context($request->user(), $subscription)]);
        }
        $search = trim($data['search'] ?? '');
        $query->when($search !== '', fn ($query) => $query->where(fn ($names) => $names->where('full_name', 'like', '%'.$search.'%')->orWhere('subscription_name', 'like', '%'.$search.'%')->orWhere('account_number', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')));

        return response()->json(['options' => $query->orderBy('id')->limit(16)->get()->take(15)->map(fn (Subscription $subscription): array => ['value' => (string) $subscription->id, 'label' => $subscription->displayName(), 'hint' => (string) $subscription->account_number])->values()]);
    }

    public function store(StoreCompanyDocumentRequest $request): RedirectResponse
    {
        $asset = null;
        try {
            $document = DB::transaction(function () use ($request, &$asset): CompanyDocument {
                $actor = $request->user();
                User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $existing = CompanyDocument::query()->visibleTo($actor)->where('operation_id', $request->validated('operation_id'))->first();
                if ($existing !== null) {
                    abort_unless($existing->created_by === $actor->id, 403);

                    return $existing;
                }
                $mode = $request->validated('mode');
                $subscription = $mode !== 'blank' && $request->filled('subscription_id')
                    ? Subscription::query()->visibleTo($actor)->findOrFail($request->validated('subscription_id'))
                    : null;
                $template = $mode === 'archive' ? null : CompanyFormTemplate::query()->lockForUpdate()->findOrFail($request->validated('company_form_template_id'));
                if ($template !== null && $template->version !== (int) $request->validated('template_version')) {
                    throw ValidationException::withMessages(['template_version' => 'تغيّر النموذج منذ فتحه. افتحه مجددًا وراجع النسخة الجديدة قبل الإصدار.']);
                }
                $layout = $template?->layout ?? CompanyForms::defaultLayout();
                $values = [];
                if ($mode === 'filled') {
                    $allowed = collect(CompanyForms::fields($layout))->pluck('key')->all();
                    $values = array_intersect_key([...CompanyForms::context($actor, $subscription), ...$request->validated('values', [])], array_flip($allowed));
                }
                if ($mode === 'archive') {
                    $layout['header']['enabled'] = false;
                    $layout['watermark']['enabled'] = false;
                    $layout['footer'] = '';
                    $layout['pages'] = [['id' => 'archive', 'blocks' => []]];
                    $asset = CompanyForms::storeFile($request->file('attachment'));
                }
                $source = $template?->source_path ? ['disk' => $template->source_disk, 'path' => $template->source_path, 'name' => $template->source_name, 'mime' => $template->source_mime] : $asset;

                return CompanyDocument::create([
                    ...$request->safe()->only(['operation_id', 'mode', 'paper_reference', 'paper_date', 'notes']),
                    'company_form_template_id' => $template?->id,
                    'title' => $template?->name ?? $request->validated('title'),
                    'subscription_id' => $subscription?->id, 'branch_id' => $subscription?->branch_id ?? $actor->workBranchId(),
                    'created_by' => $actor->id,
                    'snapshot' => ['layout' => $layout, 'values' => $values, 'source' => $source, 'templateVersion' => $template?->version],
                    ...($asset ? ['attachment_disk' => $asset['disk'], 'attachment_path' => $asset['path'], 'attachment_name' => $asset['name'], 'attachment_mime' => $asset['mime']] : []),
                ]);
            });
        } catch (Throwable $error) {
            if ($asset) {
                Storage::disk($asset['disk'])->delete($asset['path']);
            }
            if ($error instanceof UniqueConstraintViolationException) {
                abort(409, 'تعذّر تسجيل الطلب. أعد فتح النافذة وحاول مرة أخرى.');
            }
            throw $error;
        }

        return to_route('company-documents.show', $document)->with('status', 'company-document-created');
    }

    public function show(Request $request, CompanyDocument $companyDocument): Response
    {
        abort_unless(CompanyDocument::query()->visibleTo($request->user())->whereKey($companyDocument->id)->exists(), 404);
        $this->authorize('view', $companyDocument);
        $snapshot = $companyDocument->snapshot;
        $source = $snapshot['source'] ?? null;
        $snapshot['source'] = $source ? ['name' => $source['name'], 'mime' => $source['mime'], 'url' => route('company-documents.source', $companyDocument, absolute: false)] : null;

        return Inertia::render('CompanyForms/Print', [
            'document' => ['id' => $companyDocument->id, 'title' => $companyDocument->title, 'mode' => $companyDocument->mode,
                'createdAt' => $companyDocument->created_at->toIso8601String(), 'paperReference' => $companyDocument->paper_reference,
                'paperDate' => $companyDocument->paper_date?->toDateString(), 'notes' => $companyDocument->notes, 'snapshot' => $snapshot],
        ]);
    }

    public function source(Request $request, CompanyDocument $companyDocument): StreamedResponse
    {
        abort_unless(CompanyDocument::query()->visibleTo($request->user())->whereKey($companyDocument->id)->exists(), 404);
        $this->authorize('view', $companyDocument);
        $source = $companyDocument->snapshot['source'] ?? null;
        abort_unless($source && Storage::disk($source['disk'])->exists($source['path']), 404);

        return Storage::disk($source['disk'])->response($source['path'], $source['name'], ['Content-Type' => $source['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'], $request->boolean('download') ? 'attachment' : 'inline');
    }
}
