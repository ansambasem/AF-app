<?php

namespace App\Support;

use App\Enums\ReadingAssignmentChannel;
use App\Enums\ReadingAssignmentStatus;
use App\Models\MeterBox;
use App\Models\ReadingAssignment;
use App\Models\ReadingCycle;
use App\Models\ReadingDistribution;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Allocating a reading cycle's meter boxes: to a field reader, to the paper
 * sheets, or back to the available pool, then approving them. Every box is
 * moved on its own, under a lock, and only from the version the person saw:
 * when someone else moved it first, or the database already holds an
 * allocation made at the same moment, it is reported as a conflict instead.
 */
class ReadingAllocation
{
    /** Why a box was not moved, and how it reads. */
    public const CONFLICTS = [
        'not_eligible' => 'الطبلون ليس من طبلونات الفرع المفعّلة',
        'changed' => 'غيّر توزيعَه مستخدمٌ آخر للتو؛ حدّث الصفحة وأعد المحاولة',
        'downloaded' => 'نزّله القارئ على جهازه وقد تكون لديه قراءات لم تُرفع؛ أكّد النقل لنقله',
        'reader_not_eligible' => 'القارئ غير فعّال أو لا يملك صلاحية تسجيل القراءات',
        'closed' => 'دورة القراءة مغلقة',
    ];

    /**
     * Move the boxes to the target: a reader (`$channel` mobile with
     * `$reader`), the paper sheets, or the pool (`$channel` null).
     * `$expectedVersions` holds, by box id, the version each box had on the
     * person's screen (0 for one never allocated). A box a reader has
     * downloaded moves only with `$confirmDownloaded`, and its new version
     * makes the reader's copy out of date.
     *
     * @param  array<int, int>  $boxIds
     * @param  array<int|string, int>  $expectedVersions
     * @return array{moved: int, conflicts: array<int, array{boxId: int, label: string, reason: string}>}
     */
    public function allocate(ReadingCycle $cycle, array $boxIds, ?ReadingAssignmentChannel $channel, ?User $reader, array $expectedVersions, bool $confirmDownloaded, User $actor, bool $recurring = false, bool $publish = false): array
    {
        $boxes = MeterBox::query()->whereIn('id', $boxIds)->get()->keyBy('id');
        $eligible = $cycle->eligibleMeterBoxes()->pluck('id')->flip();
        $readerIsEligible = $channel !== ReadingAssignmentChannel::Mobile || ($reader !== null && $cycle->eligibleReaders()->contains('id', $reader->id));
        $moved = 0;
        $conflicts = [];

        foreach ($boxIds as $boxId) {
            $box = $boxes->get($boxId);
            $why = match (true) {
                $cycle->isClosed() => 'closed',
                $box === null || ! $eligible->has($boxId) => 'not_eligible',
                ! $readerIsEligible => 'reader_not_eligible',
                default => DB::transaction(function () use ($cycle, $box, $channel, $reader, $expectedVersions, $boxId, $confirmDownloaded, $actor, $recurring, $publish): ?string {
                    $issue = $this->move($cycle, $box, $channel, $reader, $expectedVersions[$boxId] ?? null, $confirmDownloaded, $actor);

                    if ($issue !== null) {
                        return $issue;
                    }

                    if ($recurring) {
                        if ($channel === null) {
                            ReadingDistribution::query()->where('meter_box_id', $box->id)->delete();
                        } else {
                            ReadingDistribution::updateOrCreate(['meter_box_id' => $box->id], [
                                'branch_id' => $cycle->branch_id, 'channel' => $channel,
                                'user_id' => $reader?->id, 'approved_by' => $actor->id,
                            ]);
                        }
                    }

                    if ($publish && $channel !== null) {
                        $assignment = $cycle->assignments()->where('meter_box_id', $box->id)->lockForUpdate()->firstOrFail();
                        if (! $assignment->isApproved()) {
                            $assignment->update(['status' => ReadingAssignmentStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now()]);
                            $assignment->recordEvent('approved', $actor);
                        }
                    }

                    return null;
                }),
            };

            if ($why === null) {
                $moved++;
            } else {
                $conflicts[] = ['boxId' => (int) $boxId, 'label' => $box?->label() ?? '#'.$boxId, 'reason' => self::CONFLICTS[$why]];
            }
        }

        return ['moved' => $moved, 'conflicts' => $conflicts];
    }

    /**
     * Move one box, returning why it could not be moved, or null.
     */
    private function move(ReadingCycle $cycle, MeterBox $box, ?ReadingAssignmentChannel $channel, ?User $reader, ?int $expectedVersion, bool $confirmDownloaded, User $actor): ?string
    {
        try {
            return DB::transaction(function () use ($cycle, $box, $channel, $reader, $expectedVersion, $confirmDownloaded, $actor): ?string {
                $assignment = ReadingAssignment::query()
                    ->where('reading_cycle_id', $cycle->id)
                    ->where('meter_box_id', $box->id)
                    ->lockForUpdate()
                    ->first();

                if ($expectedVersion !== null && $expectedVersion !== ($assignment?->version ?? 0)) {
                    return 'changed';
                }

                $userId = $channel === ReadingAssignmentChannel::Mobile ? $reader->id : null;

                if ($assignment === null) {
                    if ($channel === null) {
                        return null;
                    }

                    $assignment = ReadingAssignment::create([
                        'reading_cycle_id' => $cycle->id,
                        'meter_box_id' => $box->id,
                        'branch_id' => $cycle->branch_id,
                        'channel' => $channel,
                        'user_id' => $userId,
                        'status' => ReadingAssignmentStatus::Draft,
                        'version' => 1,
                        'assigned_by' => $actor->id,
                    ]);
                    $assignment->recordEvent('assigned', $actor);

                    return null;
                }

                $unchanged = $assignment->status !== ReadingAssignmentStatus::Unassigned
                    ? $assignment->channel === $channel && $assignment->user_id === $userId
                    : $channel === null;

                if ($unchanged) {
                    return null;
                }

                if ($assignment->wasDownloaded() && $assignment->channel === ReadingAssignmentChannel::Mobile && ! $confirmDownloaded) {
                    return 'downloaded';
                }

                $fromChannel = $assignment->status === ReadingAssignmentStatus::Unassigned ? null : $assignment->channel;
                $fromUserId = $assignment->user_id;
                $wasDownloaded = $assignment->wasDownloaded();

                $assignment->update([
                    'channel' => $channel,
                    'user_id' => $userId,
                    'status' => $channel === null ? ReadingAssignmentStatus::Unassigned : ReadingAssignmentStatus::Draft,
                    'version' => $assignment->version + 1,
                    'assigned_by' => $actor->id,
                    'approved_by' => null,
                    'approved_at' => null,
                    // Whoever holds it now has not downloaded it; an earlier holder's copy is out of date.
                    'downloaded_at' => null,
                    'downloaded_version' => null,
                ]);
                $assignment->recordEvent(match (true) {
                    $channel === null => 'unassigned',
                    $wasDownloaded => 'reassigned',
                    default => 'moved',
                }, $actor, $fromChannel, $fromUserId);

                return null;
            });
        } catch (UniqueConstraintViolationException) {
            // Allocated by someone else at the same moment.
            return 'changed';
        }
    }

    /**
     * Approve the drafts of one reader, of the paper sheets, or every draft
     * (`$reader` null and `$paperOnly` false). A reader who is no longer
     * active, or may no longer record readings, is left out.
     *
     * @return array{approved: int, skipped: int}
     */
    public function approve(ReadingCycle $cycle, ?User $reader, bool $paperOnly, User $actor): array
    {
        $eligibleReaderIds = $cycle->eligibleReaders()->pluck('id');
        $approved = 0;
        $skipped = 0;

        DB::transaction(function () use ($cycle, $reader, $paperOnly, $actor, $eligibleReaderIds, &$approved, &$skipped): void {
            $drafts = $cycle->assignments()
                ->where('status', ReadingAssignmentStatus::Draft)
                ->when($reader !== null, fn ($query) => $query->where('channel', ReadingAssignmentChannel::Mobile)->where('user_id', $reader->id))
                ->when($paperOnly, fn ($query) => $query->where('channel', ReadingAssignmentChannel::Paper))
                ->lockForUpdate()
                ->get();

            foreach ($drafts as $assignment) {
                if ($assignment->channel === ReadingAssignmentChannel::Mobile && ! $eligibleReaderIds->contains($assignment->user_id)) {
                    $skipped++;

                    continue;
                }

                $assignment->update(['status' => ReadingAssignmentStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now()]);
                $assignment->recordEvent('approved', $actor, $assignment->channel, $assignment->user_id);
                $approved++;
            }
        });

        return ['approved' => $approved, 'skipped' => $skipped];
    }

    /**
     * Start the cycle's allocation from the branch's previous cycle: each box
     * goes, as a draft, where it went then — when the box is still read, the
     * reader is still eligible and the box is not allocated here yet.
     * Readings and progress are never copied.
     *
     * @return array{copied: int, skipped: array<int, array{label: string, reason: string}>}
     */
    public function copyPrevious(ReadingCycle $cycle, User $actor): array
    {
        $previous = $cycle->previous();

        if ($previous === null) {
            return ['copied' => 0, 'skipped' => []];
        }

        $eligibleBoxes = $cycle->eligibleMeterBoxes()->keyBy('id');
        $eligibleReaderIds = $cycle->eligibleReaders()->pluck('id');
        $allocatedHere = $cycle->activeAssignments()->pluck('meter_box_id')->flip();
        $copied = 0;
        $skipped = [];

        foreach ($previous->activeAssignments()->with(['meterBox', 'user'])->get() as $earlier) {
            $why = match (true) {
                ! $eligibleBoxes->has($earlier->meter_box_id) => 'الطبلون لم يعد فيه مشتركون فعّالون',
                $earlier->channel === ReadingAssignmentChannel::Mobile && ! $eligibleReaderIds->contains($earlier->user_id) => 'القارئ لم يعد فعّالًا أو لا يملك صلاحية القراءات',
                $allocatedHere->has($earlier->meter_box_id) => 'موزّع في هذه الدورة مسبقًا',
                default => null,
            };

            if ($why === null && $this->move($cycle, $earlier->meterBox, $earlier->channel, $earlier->user, null, false, $actor) !== null) {
                $why = 'موزّع في هذه الدورة مسبقًا';
            }

            if ($why === null) {
                $copied++;
            } else {
                $skipped[] = ['label' => $earlier->meterBox->label(), 'reason' => $why];
            }
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }
}
