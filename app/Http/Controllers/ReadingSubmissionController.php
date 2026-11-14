<?php

namespace App\Http\Controllers;

use App\Enums\ReadingSubmissionStatus;
use App\Http\Concerns\FiltersDataTable;
use App\Models\ReadingCycle;
use App\Models\ReadingSubmission;
use App\Notifications\ActionCompleted;
use App\Support\FieldReadingSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The readings a cycle's field readers uploaded, with what became of each:
 * a supervisor saves or refuses the ones held for review.
 */
class ReadingSubmissionController extends Controller
{
    use FiltersDataTable;

    public function __construct(private FieldReadingSync $sync) {}

    public function index(Request $request, ReadingCycle $readingCycle): InertiaResponse
    {
        $this->authorize('view', $readingCycle);

        $cycle = $readingCycle->load('branch');
        $query = $cycle->submissions()->getQuery()->with(['subscription', 'user', 'assignment.meterBox', 'reviewedBy']);
        $this->applyDataTableFilters($query, $request, [], ['received_at', 'captured_at'], 'received_at', 'desc');
        $this->applyDataTableFilterSelects($query, $request, ['status']);
        $timezone = config('app.business_timezone');

        $submissions = $query->paginate($this->dataTablePerPage($request, 25))
            ->withQueryString()
            ->through(fn (ReadingSubmission $submission) => [
                'id' => $submission->id,
                'name' => $submission->subscription->displayName(),
                'accountNumber' => $submission->subscription->account_number,
                'boxLabel' => $submission->assignment->meterBox->label(),
                'readerName' => $submission->user->name,
                'currentReading' => $submission->current_reading,
                'capturedAt' => $submission->captured_at->timezone($timezone)->format('Y-m-d H:i'),
                'receivedAt' => $submission->received_at->timezone($timezone)->format('Y-m-d H:i'),
                'status' => $submission->status->value,
                'statusLabel' => __($submission->status->label()),
                'issue' => $submission->issueMessage(),
                'reviewedBy' => $submission->reviewedBy?->name,
                'reviewNote' => $submission->review_note,
            ]);

        return Inertia::render('ReadingCycles/Submissions', [
            'cycle' => ['id' => $cycle->id, 'branchName' => $cycle->branch->name, 'readingDate' => $cycle->reading_date->toDateString(), 'weekEnd' => $cycle->week_end->toDateString()],
            'submissions' => $submissions,
            'canReview' => $request->user()->can('review', $cycle),
            'filters' => $this->dataTableState($request, 'received_at', 'desc', 25),
            'filterOptions' => [$this->filterGroup('status', 'الحالة', ReadingSubmissionStatus::options())],
        ]);
    }

    /**
     * Save a held reading as the week's reading, or refuse it.
     */
    public function review(Request $request, ReadingSubmission $readingSubmission): RedirectResponse
    {
        $this->authorize('review', $readingSubmission->cycle);

        $validated = $request->validate([
            'decision' => ['required', 'in:accept,reject'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['decision'] === 'accept') {
            $why = $this->sync->accept($readingSubmission, $request->user(), $validated['note'] ?? null);

            if ($why !== null) {
                return back()->withErrors(['decision' => $why]);
            }
        } elseif (! $this->sync->reject($readingSubmission, $request->user(), $validated['note'] ?? null)) {
            return back()->withErrors(['decision' => 'تمت مراجعة هذه القراءة من قبل.']);
        }

        $status = $validated['decision'] === 'accept' ? 'reading-submission-accepted' : 'reading-submission-rejected';
        $request->user()->notify(new ActionCompleted($status, $readingSubmission->subscription->displayName()));

        return back()->with('status', $status);
    }
}
