<?php

namespace App\Http\Concerns;

use App\Notifications\ActionCompleted;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Deleting a record from its list: allowed by its policy's `delete`, and
 * only once nothing uses it any more — otherwise the user is told what
 * still does (the model's deletionBlocker()), under the `delete` error.
 */
trait DeletesRecords
{
    /**
     * @param  string  $action  the flashed status, e.g. `branch-deleted`
     * @param  string  $subject  the record's name, for the activity list
     * @param  (Closure(): void)|null  $delete  how to delete it, when more than delete()
     */
    protected function deleteRecord(Request $request, Model $record, string $action, string $subject, ?Closure $delete = null): RedirectResponse
    {
        $this->authorize('delete', $record);

        $blocker = $record->deletionBlocker();

        if ($blocker !== null) {
            return back()->withErrors(['delete' => $blocker]);
        }

        $delete ? $delete() : $record->delete();

        $request->user()->notify(new ActionCompleted($action, $subject));

        return back()->with('status', $action);
    }
}
