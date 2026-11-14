<?php

namespace App\Http\Controllers;

use App\Enums\ReadingAssignmentChannel;
use App\Http\Requests\AllocateReadingBoxesRequest;
use App\Models\ReadingCycle;
use App\Models\User;
use App\Notifications\ActionCompleted;
use App\Support\ReadingAllocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Moving a cycle's meter boxes on its board and approving them. The result
 * of a move — how many boxes moved and each one that did not, and why — is
 * flashed for the board to show.
 */
class ReadingAssignmentController extends Controller
{
    public function __construct(private ReadingAllocation $allocation) {}

    /**
     * Move the boxes to a reader, to the paper sheets or back to the pool.
     */
    public function store(AllocateReadingBoxesRequest $request, ReadingCycle $readingCycle): RedirectResponse
    {
        $target = $request->validated('target');
        $channel = $target === 'available' ? null : ReadingAssignmentChannel::from($target);
        $reader = $channel === ReadingAssignmentChannel::Mobile ? User::query()->find($request->validated('user_id')) : null;

        $result = $this->allocation->allocate(
            $readingCycle,
            array_map('intval', $request->validated('box_ids')),
            $channel,
            $reader,
            array_map('intval', $request->validated('versions') ?? []),
            $request->boolean('confirm_downloaded'),
            $request->user(),
            $request->boolean('recurring'),
            $request->boolean('publish') || $request->boolean('recurring'),
        );

        Inertia::flash('allocationResult', ['action' => 'move', 'moved' => $result['moved'], 'conflicts' => $result['conflicts']]);

        if ($result['moved'] === 0) {
            return back()->withErrors(['box_ids' => 'لم يُنقل أي طبلون؛ راجع الأسباب.']);
        }

        return back()->with('status', 'reading-boxes-allocated');
    }

    /**
     * Approve the drafts of one reader (`user_id`), of the paper sheets
     * (`paper`), or all of them.
     */
    public function approve(Request $request, ReadingCycle $readingCycle): RedirectResponse
    {
        $this->authorize('approve', $readingCycle);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'paper' => ['boolean'],
        ]);

        if ($readingCycle->isClosed()) {
            return back()->withErrors(['approve' => 'دورة القراءة مغلقة.']);
        }

        $reader = isset($validated['user_id']) ? User::query()->findOrFail($validated['user_id']) : null;
        $result = $this->allocation->approve($readingCycle, $reader, $request->boolean('paper'), $request->user());

        Inertia::flash('allocationResult', ['action' => 'approve', 'moved' => $result['approved'], 'conflicts' => $result['skipped'] > 0
            ? [['boxId' => null, 'label' => $result['skipped'].' طبلون', 'reason' => ReadingAllocation::CONFLICTS['reader_not_eligible']]]
            : []]);

        if ($result['approved'] === 0) {
            return back()->withErrors(['approve' => 'لا يوجد توزيع بانتظار الاعتماد.']);
        }

        $request->user()->notify(new ActionCompleted('reading-assignments-approved', (string) $result['approved']));

        return back()->with('status', 'reading-assignments-approved');
    }
}
