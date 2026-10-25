<?php

namespace App\Http\Controllers;

use App\Enums\ReadingEntryMode;
use App\Http\Requests\UpdateReadingScheduleRequest;
use App\Models\Branch;
use App\Models\ReadingEntrySetting;
use App\Notifications\ActionCompleted;
use App\Support\BranchDomainContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The weekly reading day and entry schedule. Each branch's admin sets their own
 * branch's; the Super Admin sets any branch's and the company's default, which
 * the branches without a schedule of their own follow.
 */
class ReadingScheduleController extends Controller
{
    /**
     * Show the weekly reading day and entry schedule of the branch, or the
     * company's default.
     */
    public function edit(Request $request): InertiaResponse
    {
        $this->authorize('manageAny', ReadingEntrySetting::class);

        $actor = $request->user();
        $requested = $request->integer('branch') ?: (BranchDomainContext::id() ?? ($actor->isSuperAdmin() ? null : $actor->workBranchId()));
        $branch = $requested === null ? null : Branch::query()->findOrFail($requested);
        $this->authorize('manage', [ReadingEntrySetting::class, $branch]);

        $setting = ReadingEntrySetting::forBranch($branch)->load('updatedBy');
        $businessNow = now()->timezone(config('app.business_timezone'));
        $businessDate = Carbon::parse($businessNow->toDateString());
        $currentWeeks = [];

        foreach (range(0, 6) as $readingDay) {
            $preview = $setting->replicate();
            $preview->changeReadingDay($readingDay);
            $currentWeeks[$readingDay] = [
                'start' => $preview->weekStartFor($businessDate)->toDateString(),
                'end' => $preview->weekEndFor($businessDate)->toDateString(),
            ];
        }

        return Inertia::render('Settings/ReadingSchedule', [
            'setting' => [
                'reading_day' => $setting->reading_day,
                'assignment_lead_days' => $setting->assignment_lead_days ?? 1,
                'open_days' => array_map('intval', $setting->open_days),
                'opens_at' => substr($setting->opens_at, 0, 5),
                'closes_at' => substr($setting->closes_at, 0, 5),
                'mode' => $setting->mode->value,
                'reading_day_history' => $setting->reading_day_history ?? [],
                'isOpenNow' => $setting->isOpen(),
                'latestWeekEnd' => $setting->weekEndFor($setting->latestEndedWeekStart())->toDateString(),
                'updatedByName' => $setting->updatedBy?->name,
                'updatedAt' => $setting->updated_at?->timezone(config('app.business_timezone'))->format('Y-m-d H:i'),
            ],
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
            'branches' => $actor->isSuperAdmin()
                ? Branch::query()->orderBy('name')->get()->map(fn (Branch $item): array => [
                    'value' => $item->id,
                    'label' => $item->name,
                    'hasOwn' => ReadingEntrySetting::ownFor($item) !== null,
                ])->all()
                : [],
            'followsCompany' => $branch !== null && ReadingEntrySetting::ownFor($branch) === null,
            'branchesWithOwn' => ReadingEntrySetting::ownCount(),
            'modes' => ReadingEntryMode::options(),
            // What choosing each reading day would do to the next week.
            'firstWeeks' => $setting->firstWeekOnEachReadingDay(),
            'currentWeeks' => $currentWeeks,
            'businessNow' => $businessNow->toIso8601String(),
            'businessTimezone' => config('app.business_timezone'),
        ]);
    }

    /**
     * Update the schedule of the branch, or the company's default when no
     * branch is given. A new reading day shapes the weeks after the latest
     * one that has ended; weeks already ended keep their dates. A branch that
     * still followed the company's gets a schedule of its own, starting from
     * a copy of the company's, the first time its admin saves one.
     */
    public function update(UpdateReadingScheduleRequest $request): RedirectResponse
    {
        $branch = $request->branch();
        $setting = $branch === null
            ? ReadingEntrySetting::company()
            : (ReadingEntrySetting::ownFor($branch) ?? ReadingEntrySetting::startFor($branch));

        $setting->changeReadingDay($request->integer('reading_day'));

        $setting->fill([
            'open_days' => array_map('intval', $request->validated('open_days')),
            'mode' => $request->validated('mode'),
            'updated_by' => $request->user()->id,
        ] + $request->safe()->only(['opens_at', 'closes_at', 'assignment_lead_days']))->save();

        $request->user()->notify(new ActionCompleted('reading-schedule-updated'));

        return redirect()->route('settings.reading-schedule.edit', $branch ? ['branch' => $branch->id] : [])
            ->with('status', 'reading-schedule-updated');
    }
}
