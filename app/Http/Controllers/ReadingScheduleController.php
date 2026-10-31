<?php

namespace App\Http\Controllers;

use App\Enums\ReadingEntryMode;
use App\Http\Requests\UpdateReadingScheduleRequest;
use App\Models\Branch;
use App\Models\ReadingEntrySetting;
use App\Notifications\ActionCompleted;
use App\Support\BranchDomainContext;
use App\Support\BranchDomainScope;
use App\Support\BranchScope;
use App\Support\BranchViewContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The weekly reading day and entry schedule. Each branch's admin sets their own
 * branch's; the Super Admin sets any branch's or applies the company's schedule
 * to every branch, including those with their own settings.
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
        $branches = $actor->isSuperAdmin() ? Branch::query()
            ->when(BranchViewContext::id() !== null, fn (Builder $query): Builder => $query->withoutGlobalScope(BranchDomainScope::class))
            ->orderBy('name')->get() : collect();
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
            'branchScope' => $branch !== null ? BranchScope::describe(collect([$branch])) : [
                ...BranchScope::describe($branches), 'level' => 'all', 'all' => true, 'name' => 'جميع الفروع',
            ],
            'branches' => $branches->map(fn (Branch $item): array => [
                'value' => $item->id,
                'label' => $item->name,
                'hasOwn' => ReadingEntrySetting::ownFor($item) !== null,
            ])->all(),
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
     * Update one branch, or apply the company's schedule to every branch.
     * Each schedule keeps its own history so ended reading weeks retain
     * their dates, even when the branches previously read on different days.
     */
    public function update(UpdateReadingScheduleRequest $request): RedirectResponse
    {
        $branch = $request->branch();
        DB::transaction(function () use ($request, $branch): void {
            $setting = $branch === null
                ? ReadingEntrySetting::company()
                : (ReadingEntrySetting::ownFor($branch) ?? ReadingEntrySetting::startFor($branch));
            $attributes = [
                'open_days' => array_map('intval', $request->validated('open_days')),
                'mode' => $request->validated('mode'),
                'updated_by' => $request->user()->id,
            ] + $request->safe()->only(['opens_at', 'closes_at', 'assignment_lead_days'])
                + $setting->only(['opens_at', 'closes_at', 'assignment_lead_days']);
            $settings = $branch === null
                ? ReadingEntrySetting::query()->whereNotNull('branch_id')->orderBy('id')->lockForUpdate()->get()->prepend($setting)
                : collect([$setting]);

            foreach ($settings as $schedule) {
                $schedule->changeReadingDay($request->integer('reading_day'));
                $schedule->fill($attributes)->save();
            }
        });

        $request->user()->notify(new ActionCompleted('reading-schedule-updated'));

        return redirect()->route('settings.reading-schedule.edit', $branch ? ['branch' => $branch->id] : [])
            ->with('status', 'reading-schedule-updated');
    }
}
