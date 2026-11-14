<?php

namespace App\Http\Controllers;

use App\Enums\CashBoxType;
use App\Http\Requests\BranchClosingActionRequest;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\ClosingSetting;
use App\Models\Subscription;
use App\Support\BranchClosingAccess;
use App\Support\BranchClosingWorkflow;
use Illuminate\Http\RedirectResponse;

class BranchClosingController extends Controller
{
    public function __construct(private BranchClosingWorkflow $workflow) {}

    /**
     * What the branch does with its boxes: start using them, confirm a box
     * closed (one, or several of a type at once), mark a transfer or
     * clearing, and let a collector confirm their own box.
     */
    public function action(BranchClosingActionRequest $request, string $action, ?int $record = null): RedirectResponse
    {
        $actor = $request->user();
        $branchId = $request->integer('branch_id');
        abort_unless($actor->isSuperAdmin() || $actor->workBranchId() === $branchId, 404);
        $data = $request->validated();
        $allowed = match ($action) {
            'confirm' => $actor->is_active && $actor->can('viewOwnCashBoxes', Subscription::class),
            'receive', 'receive-type', 'electronic' => BranchClosingAccess::receive($actor, $branchId),
            'start' => BranchClosingAccess::manage($actor, $branchId),
            default => false,
        };
        abort_unless($allowed, 403);
        $this->workflow->locked($branchId, function () use ($action, $record, $actor, $branchId, $data): void {
            if ($action === 'start') {
                $this->workflow->start($branchId, $actor);

                return;
            }
            $this->workflow->refresh($branchId);
            abort_unless(ClosingSetting::ownFor($branchId)?->workflow_started_at, 404);
            if (in_array($action, ['receive', 'confirm'], true)) {
                $box = CashBox::query()->where('branch_id', $branchId)->findOrFail($record);
                match ($action) {
                    'receive' => $this->workflow->receive($box, $actor),
                    'confirm' => $this->workflow->confirmBox($box, $actor),
                };
            } elseif ($action === 'receive-type') {
                $this->workflow->receiveType($branchId, $actor, CashBoxType::from($data['type']), array_map('intval', $data['box_ids']));
            } else {
                $payment = CashBoxPayment::query()->whereHas('box', fn ($query) => $query->where('branch_id', $branchId))->findOrFail($record);
                $this->workflow->reviewEntry($payment, $actor, $data['status']);
            }
        });

        return back()->with('status', 'تم حفظ الإجراء بنجاح.');
    }
}
