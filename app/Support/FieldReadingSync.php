<?php

namespace App\Support;

use App\Enums\ReadingAssignmentChannel;
use App\Enums\ReadingAssignmentStatus;
use App\Enums\ReadingSubmissionStatus;
use App\Enums\SubscriptionStatus;
use App\Models\MeterReading;
use App\Models\ReadingAssignment;
use App\Models\ReadingSubmission;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Readings the field app uploads for its assignments, one at a time. Each is
 * kept as a submission, whatever becomes of it: saved as the week's reading
 * (pending the usual approval) when everything checks out; held for a
 * supervisor when it was taken outside the reading day, after the box was
 * reassigned, on an untrusted clock, after the upload deadline or when the
 * subscription already has a reading; refused when it cannot belong to the
 * assignment at all. An upload retried with the same operation id is
 * answered with what was decided the first time.
 */
class FieldReadingSync
{
    /** How far ahead of the server the phone's clock may be, in minutes. */
    public const CLOCK_TOLERANCE_MINUTES = 10;

    /**
     * What became of one uploaded reading: `status` (accepted, needs_review
     * or rejected), the `issue` and its `message` when it was not accepted,
     * and the week's reading it became.
     *
     * @param  array{operation_id: string, assignment_id: int, authorization: string, subscription_id: int, current_reading: float|string, captured_at: string, device_id?: ?string, notes?: ?string}  $reading
     * @return array{operation_id: string, status: string, issue: ?string, message: ?string, meter_reading_id: ?int}
     */
    public function submit(User $reader, array $reading): array
    {
        if ($existing = ReadingSubmission::query()->where('operation_id', $reading['operation_id'])->first()) {
            return $existing->user_id === $reader->id ? $this->result($existing)
                : ['operation_id' => $reading['operation_id'], 'status' => ReadingSubmissionStatus::Rejected->value, 'issue' => 'not_your_assignment', 'message' => ReadingSubmission::ISSUES['not_your_assignment'], 'meter_reading_id' => null];
        }

        try {
            $submission = DB::transaction(fn (): ?ReadingSubmission => $this->record($reader, $reading));
        } catch (UniqueConstraintViolationException) {
            // The same upload arrived twice at once: answer with what the other one decided.
            $submission = ReadingSubmission::query()->where('operation_id', $reading['operation_id'])->firstOrFail();
            if ($submission->user_id !== $reader->id) {
                return ['operation_id' => $reading['operation_id'], 'status' => ReadingSubmissionStatus::Rejected->value, 'issue' => 'not_your_assignment', 'message' => ReadingSubmission::ISSUES['not_your_assignment'], 'meter_reading_id' => null];
            }
        }

        // One that names no assignment of the reader's branch or no subscription is refused without being kept.
        return $submission === null
            ? ['operation_id' => $reading['operation_id'], 'status' => ReadingSubmissionStatus::Rejected->value, 'issue' => 'not_your_assignment', 'message' => ReadingSubmission::ISSUES['not_your_assignment'], 'meter_reading_id' => null]
            : $this->result($submission);
    }

    /**
     * @return array{operation_id: string, status: string, issue: ?string, message: ?string, meter_reading_id: ?int}
     */
    private function result(ReadingSubmission $submission): array
    {
        return [
            'operation_id' => $submission->operation_id,
            'status' => $submission->status->value,
            'issue' => $submission->issue,
            'message' => $submission->issueMessage(),
            'meter_reading_id' => $submission->meter_reading_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $reading
     */
    private function record(User $reader, array $reading): ?ReadingSubmission
    {
        $assignment = ReadingAssignment::query()->with('cycle')->lockForUpdate()->find($reading['assignment_id']);
        $subscription = Subscription::query()->with(['tariff', 'circuitBreaker', 'standingDiscount'])->lockForUpdate()->find($reading['subscription_id']);

        if ($assignment === null || $assignment->branch_id !== $reader->branch_id || $subscription === null) {
            return null;
        }

        $cycle = $assignment->cycle;
        $capturedAt = Carbon::parse($reading['captured_at']);
        $claims = FieldReadingAuthorization::verify((string) $reading['authorization'], $assignment, $reader);
        $currentReading = round((float) $reading['current_reading'], 2);

        $issue = match (true) {
            $claims === null => 'invalid_authorization',
            $subscription->branch_id !== $assignment->branch_id || $subscription->archived_at !== null
                || $subscription->meter_box_id !== $assignment->meter_box_id || $subscription->status !== SubscriptionStatus::Active => 'not_on_box',
            default => null,
        };
        $status = $issue === null ? null : ReadingSubmissionStatus::Rejected;

        if ($issue === null) {
            $issue = $this->reviewIssue($assignment, $reader, (int) $claims['version'], $subscription, $capturedAt, $currentReading);
            $status = $issue === null ? ReadingSubmissionStatus::Accepted : ReadingSubmissionStatus::NeedsReview;
        }

        $submission = ReadingSubmission::create([
            'reading_cycle_id' => $cycle->id,
            'reading_assignment_id' => $assignment->id,
            'subscription_id' => $subscription->id,
            'user_id' => $reader->id,
            'operation_id' => $reading['operation_id'],
            'assignment_version' => (int) ($claims['version'] ?? 0),
            'current_reading' => $currentReading,
            'notes' => $reading['notes'] ?? null,
            'captured_at' => $capturedAt,
            'received_at' => now(),
            'device_id' => $reading['device_id'] ?? null,
            'status' => $status,
            'issue' => $issue,
        ]);

        if ($status === ReadingSubmissionStatus::Accepted) {
            $meterReading = MeterReading::recordFor($subscription, $cycle->week_start, $currentReading, $reader, $reading['notes'] ?? null, $reading['operation_id']);
            $submission->update(['meter_reading_id' => $meterReading->id]);
        }

        return $submission;
    }

    /**
     * Why an upload that belongs to the assignment must wait for a
     * supervisor, or null when it can be saved as the week's reading.
     */
    private function reviewIssue(ReadingAssignment $assignment, User $reader, int $version, Subscription $subscription, Carbon $capturedAt, float $currentReading): ?string
    {
        $cycle = $assignment->cycle;
        $capturedDay = $capturedAt->copy()->setTimezone(config('app.business_timezone'));

        return match (true) {
            $cycle->isClosed() => 'cycle_closed',
            $assignment->status !== ReadingAssignmentStatus::Approved
                || $assignment->channel !== ReadingAssignmentChannel::Mobile
                || $assignment->user_id !== $reader->id
                || $version !== $assignment->version => 'assignment_changed',
            $capturedAt->gt(now()->addMinutes(self::CLOCK_TOLERANCE_MINUTES)) => 'untrusted_clock',
            $capturedDay->toDateString() !== $cycle->reading_date->toDateString() => 'outside_reading_day',
            now()->gt($cycle->syncDeadline()) => 'late_sync',
            $subscription->meterReadings()->whereDate('week_start', $cycle->week_start->toDateString())->exists() => 'already_read',
            $subscription->meterReadings()->whereDate('week_start', '>', $cycle->week_start->toDateString())->exists() => 'later_week_read',
            $currentReading < $subscription->previousReadingBefore($cycle->week_start) => 'below_previous',
            MeterReading::isImpossibleConsumption(MeterReading::consumptionBetween($subscription->previousReadingBefore($cycle->week_start), $currentReading)) => 'impossible_consumption',
            default => null,
        };
    }

    /**
     * A supervisor's decision to save a held reading as the week's reading,
     * after checking again that the subscription has no reading for that
     * week or a later one and that the value fits. Returns why it cannot be
     * saved, or null once it is.
     */
    public function accept(ReadingSubmission $submission, User $reviewer, ?string $note): ?string
    {
        return DB::transaction(function () use ($submission, $reviewer, $note): ?string {
            $held = ReadingSubmission::query()->with('cycle')->lockForUpdate()->findOrFail($submission->id);

            if ($held->status !== ReadingSubmissionStatus::NeedsReview) {
                return 'تمت مراجعة هذه القراءة من قبل.';
            }

            $subscription = Subscription::query()->with(['tariff', 'circuitBreaker', 'standingDiscount'])->lockForUpdate()->findOrFail($held->subscription_id);
            $weekStart = $held->cycle->week_start;
            $previous = $subscription->previousReadingBefore($weekStart);
            $why = match (true) {
                $subscription->status !== SubscriptionStatus::Active => ReadingSubmission::ISSUES['not_on_box'],
                $subscription->meterReadings()->whereDate('week_start', '>=', $weekStart->toDateString())->exists() => ReadingSubmission::ISSUES['already_read'],
                $held->current_reading < $previous => ReadingSubmission::ISSUES['below_previous'],
                MeterReading::isImpossibleConsumption(MeterReading::consumptionBetween($previous, $held->current_reading)) => ReadingSubmission::ISSUES['impossible_consumption'],
                default => null,
            };

            if ($why !== null) {
                return $why;
            }

            $meterReading = MeterReading::recordFor($subscription, $weekStart, $held->current_reading, $held->user, $held->notes, $held->operation_id);
            $held->update([
                'status' => ReadingSubmissionStatus::Accepted,
                'meter_reading_id' => $meterReading->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            return null;
        });
    }

    /**
     * A supervisor's decision not to use a held reading. It stays on record.
     */
    public function reject(ReadingSubmission $submission, User $reviewer, ?string $note): bool
    {
        return DB::transaction(function () use ($submission, $reviewer, $note): bool {
            $held = ReadingSubmission::query()->lockForUpdate()->findOrFail($submission->id);

            if ($held->status !== ReadingSubmissionStatus::NeedsReview) {
                return false;
            }

            $held->update(['status' => ReadingSubmissionStatus::Rejected, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $note]);

            return true;
        });
    }
}
