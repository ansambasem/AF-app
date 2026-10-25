<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\PermissionKey;
use App\Enums\ReadingAssignmentChannel;
use App\Enums\ReadingAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AllocateReadingBoxesRequest;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\ReadingCycle;
use App\Models\ReadingDistribution;
use App\Models\ReadingEntrySetting;
use App\Models\User;
use App\Support\ReadingAllocation;
use App\Support\RecurringReadingDistribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileReadingDistributionController extends Controller
{
    public function index(Request $request, RecurringReadingDistribution $distribution): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasAnyPermission(PermissionKey::ManageReadingAssignments, PermissionKey::ApproveReadingAssignments), 403);
        $validated = $request->validate(['branch_id' => ['nullable', 'integer', 'exists:branches,id']]);
        $branchId = $validated['branch_id'] ?? $actor->workBranchId();
        abort_unless($actor->isSuperAdmin() || (int) $branchId === $actor->workBranchId(), 403);
        $branches = $actor->isSuperAdmin() ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [];
        if ($branchId === null) {
            return response()->json(['branches' => $branches, 'branch' => null, 'cycle' => null, 'boxes' => [], 'readers' => []]);
        }

        $branch = Branch::query()->findOrFail($branchId);
        $upcoming = $distribution->upcoming($branch);
        $cycle = $actor->can('create', ReadingCycle::class) ? $distribution->prepare($branch, $actor, false) : $upcoming['cycle'];
        if ($cycle === null) {
            return response()->json(['branches' => $branches, 'branch' => $branch->only('id', 'name'), 'cycle' => null, 'boxes' => [], 'readers' => []]);
        }
        $this->authorize('view', $cycle);
        $assignments = $cycle->assignments()->get()->keyBy('meter_box_id');
        $defaults = ReadingDistribution::query()->where('branch_id', $branch->id)->get()->keyBy('meter_box_id');
        $schedule = ReadingEntrySetting::forBranch($branch);

        return response()->json([
            'branches' => $branches, 'branch' => $branch->only('id', 'name'),
            'cycle' => [
                'id' => $cycle->id, 'week_start' => $cycle->week_start->toDateString(), 'week_end' => $cycle->week_end->toDateString(),
                'reading_date' => $cycle->reading_date->toDateString(),
                'available_on' => $cycle->reading_date->copy()->subDays($schedule->assignment_lead_days ?? 1)->toDateString(),
                'closed' => $cycle->isClosed(),
            ],
            'abilities' => ['manage' => $actor->can('manage', $cycle), 'approve' => $actor->can('approve', $cycle)],
            'readers' => $cycle->eligibleReaders()->map(fn (User $reader): array => $reader->only('id', 'name'))->values(),
            'boxes' => $cycle->eligibleMeterBoxes()->map(function (MeterBox $box) use ($assignments, $defaults): array {
                $assignment = $assignments->get($box->id);
                $allocated = $assignment !== null && $assignment->status !== ReadingAssignmentStatus::Unassigned;

                return [
                    'id' => $box->id, 'name' => $box->displayName(), 'number' => $box->box_number,
                    'sub_area_id' => $box->sub_area_id, 'sub_area_name' => $box->subArea?->name ?? 'بدون منطقة 2',
                    'subscribers' => (int) $box->active_subscriptions_count, 'version' => $assignment?->version ?? 0,
                    'column' => ! $allocated ? 'available' : ($assignment->channel === ReadingAssignmentChannel::Paper ? 'paper' : 'reader:'.$assignment->user_id),
                    'status' => $allocated ? $assignment->status->value : null,
                    'recurring_user_id' => $defaults->get($box->id)?->user_id,
                    'recurring_channel' => $defaults->get($box->id)?->channel?->value,
                ];
            })->values(),
        ]);
    }

    public function store(AllocateReadingBoxesRequest $request, ReadingCycle $readingCycle, ReadingAllocation $allocation): JsonResponse
    {
        $target = $request->validated('target');
        $channel = $target === 'available' ? null : ReadingAssignmentChannel::from($target);
        $reader = $channel === ReadingAssignmentChannel::Mobile ? User::find($request->integer('user_id')) : null;
        $result = $allocation->allocate($readingCycle, array_map('intval', $request->validated('box_ids')), $channel, $reader,
            array_map('intval', $request->validated('versions') ?? []), $request->boolean('confirm_downloaded'), $request->user(),
            $request->boolean('recurring'), $request->boolean('publish') || $request->boolean('recurring'));

        return response()->json($result + ['confirmation_box_ids' => array_values(array_map(fn (array $conflict): int => $conflict['boxId'],
            array_filter($result['conflicts'], fn (array $conflict): bool => $conflict['reason'] === ReadingAllocation::CONFLICTS['downloaded'])))]);
    }

    public function approve(Request $request, ReadingCycle $readingCycle, ReadingAllocation $allocation): JsonResponse
    {
        $this->authorize('approve', $readingCycle);
        abort_if($readingCycle->isClosed(), 422, 'دورة القراءة مغلقة.');

        return response()->json($allocation->approve($readingCycle, null, false, $request->user()));
    }
}
