<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArchiveSubscriptionRequest;
use App\Models\Subscription;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionArchiveController extends Controller
{
    public function store(ArchiveSubscriptionRequest $request, Subscription $subscription): RedirectResponse
    {
        DB::transaction(function () use ($request, $subscription): void {
            $current = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $this->authorize('archive', $current);

            if ($blocker = $current->archiveBlocker()) {
                throw ValidationException::withMessages(['archive' => $blocker]);
            }

            $current->archived_at = now();
            $current->service_ended_on = $request->validated('service_ended_on');
            $current->archive_reason = $request->validated('reason');
            $current->archived_by = $request->user()->id;
            $current->archive_history = [...($current->archive_history ?? []), [
                'action' => 'archive', 'at' => now()->toIso8601String(),
                'user_id' => $request->user()->id, 'user_name' => $request->user()->name,
                'reason' => $request->validated('reason'), 'service_ended_on' => $request->validated('service_ended_on'),
            ]];
            $current->save();
        });

        $request->user()->notify(new ActionCompleted('subscription-archived', $subscription->displayName()));

        return back()->with('status', 'subscription-archived');
    }

    public function destroy(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->authorize('restore', $subscription);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'confirmed' => ['accepted']]);

        DB::transaction(function () use ($request, $subscription, $data): void {
            $current = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $this->authorize('restore', $current);

            if ($current->archived_at === null) {
                throw ValidationException::withMessages(['archive' => 'هذا الاشتراك غير مؤرشف.']);
            }

            $current->archive_history = [...($current->archive_history ?? []), [
                'action' => 'restore', 'at' => now()->toIso8601String(),
                'user_id' => $request->user()->id, 'user_name' => $request->user()->name,
                'reason' => $data['reason'],
            ]];
            $current->archived_at = null;
            $current->archived_by = null;
            $current->service_ended_on = null;
            $current->archive_reason = null;
            $current->save();
        });

        $request->user()->notify(new ActionCompleted('subscription-restored', $subscription->displayName()));

        return back()->with('status', 'subscription-restored');
    }
}
