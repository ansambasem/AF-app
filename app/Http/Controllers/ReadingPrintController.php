<?php

namespace App\Http\Controllers;

use App\Enums\ReadingAssignmentChannel;
use App\Enums\ReadingAssignmentStatus;
use App\Models\MeterBox;
use App\Models\ReadingCycle;
use App\Support\ArabicSearch;
use App\Support\PrintSelection;
use App\Support\ReadingPrintData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReadingPrintController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ReadingCycle::class);
        $validated = $request->validate([
            'cycle' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'],
            'area_ids' => ['nullable', 'array', 'max:200'], 'area_ids.*' => ['string', 'regex:/^(none|[0-9]+)$/'],
            'box_id' => ['nullable', 'integer', 'min:1'], 'assignment' => ['nullable', 'string', 'regex:/^(assigned|available|paper|reader:[0-9]+)$/'],
            'print_page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:15,25,50,100'],
        ]);
        $cycles = ReadingCycle::query()->visibleTo($request->user())->with('branch');
        $cycle = isset($validated['cycle']) ? $cycles->findOrFail($validated['cycle']) : $cycles->latest('week_start')->first();
        if ($cycle !== null) {
            $this->authorize('view', $cycle);
        }

        return Inertia::render('Print/ReadingReport', [
            'printData' => fn (): array => $this->data($request, $cycle, $validated),
        ]);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function data(Request $request, ?ReadingCycle $cycle, array $filters): array
    {
        $context = ['templatePage' => '/reading-cycles/print', 'title' => 'توزيع القراءات', 'branchName' => $cycle?->branch?->name ?? '', 'readingDate' => $cycle?->reading_date?->toDateString() ?? '', 'readingDayName' => $cycle?->reading_date?->locale('ar')->dayName ?? '', 'sourceUrl' => $cycle ? route('reading-cycles.show', $cycle) : route('reading-cycles.index')];
        $ids = PrintSelection::ids($request, '/reading-cycles/print');
        if ($cycle === null) {
            return ReadingPrintData::distribution([], $context, 0);
        }
        $assignments = $cycle->assignments()->with(['user:id,name', 'approvedBy:id,name'])->get()->keyBy('meter_box_id');
        $areas = $filters['area_ids'] ?? [];
        $terms = ArabicSearch::terms($filters['search'] ?? '');
        $boxes = $cycle->eligibleMeterBoxes()->filter(function (MeterBox $box) use ($ids, $areas, $terms, $filters, $assignments): bool {
            $assignment = $assignments->get($box->id);
            $assigned = $assignment !== null && $assignment->status !== ReadingAssignmentStatus::Unassigned;
            $column = ! $assigned ? 'available' : ($assignment->channel === ReadingAssignmentChannel::Paper ? 'paper' : 'reader:'.$assignment->user_id);
            $wanted = $filters['assignment'] ?? '';
            $text = ArabicSearch::normalize($box->label().' '.($box->subArea?->name ?? 'بدون منطقة 2'));

            return ($ids === null || in_array($box->id, $ids, true))
                && ($areas === [] || in_array((string) ($box->sub_area_id ?? 'none'), $areas, true))
                && (! isset($filters['box_id']) || $box->id === (int) $filters['box_id'])
                && ($wanted === '' || ($wanted === 'assigned' ? $assigned : $column === $wanted))
                && collect($terms)->every(fn (string $term): bool => str_contains($text, $term));
        })->values();
        $total = $boxes->count();
        $limit = $request->boolean('print_all') ? 2000 : (int) ($filters['per_page'] ?? 25);
        $offset = $request->boolean('print_all') ? 0 : ((int) ($filters['print_page'] ?? 1) - 1) * $limit;
        $rows = $boxes->slice($offset, $limit)->map(function (MeterBox $box) use ($assignments): array {
            $assignment = $assignments->get($box->id);
            $assigned = $assignment !== null && $assignment->status !== ReadingAssignmentStatus::Unassigned;

            return ['subAreaName' => $box->subArea?->name ?? 'بدون منطقة 2', 'boxName' => $box->displayName(), 'boxNumber' => $box->box_number, 'boxLabel' => $box->label(), 'subscribers' => (int) $box->active_subscriptions_count,
                'readerName' => $assigned ? ($assignment->channel === ReadingAssignmentChannel::Paper ? 'كشف ورقي' : $assignment->user?->name ?? '') : 'غير موزع',
                'channel' => $assigned ? __($assignment->channel->label()) : 'غير موزع', 'status' => $assigned ? __($assignment->status->label()) : 'غير موزع',
                'approvedBy' => $assigned ? $assignment->approvedBy?->name : null, 'approvedAt' => $assigned ? $assignment->approved_at?->timezone(config('app.business_timezone'))->format('Y-m-d H:i') : null];
        })->values()->all();

        return ReadingPrintData::distribution($rows, $context, $total);
    }
}
