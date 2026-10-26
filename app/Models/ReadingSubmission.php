<?php

namespace App\Models;

use App\Enums\ReadingSubmissionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reading as the field app uploaded it, kept whatever became of it: saved
 * as the week's reading, held for a supervisor, or refused, and why.
 */
#[Fillable([
    'reading_cycle_id', 'reading_assignment_id', 'subscription_id', 'user_id', 'operation_id', 'assignment_version', 'current_reading', 'notes',
    'captured_at', 'received_at', 'device_id', 'status', 'issue', 'meter_reading_id', 'reviewed_by', 'reviewed_at', 'review_note',
])]
class ReadingSubmission extends Model
{
    /** Why an upload was held or refused, and how it reads. */
    public const ISSUES = [
        'not_your_assignment' => 'الطبلون ليس من مهامك',
        'invalid_authorization' => 'تصريح العمل غير صالح؛ نزّل المهام من جديد',
        'not_on_box' => 'المشترك ليس على هذا الطبلون أو خدمته غير فعّالة',
        'assignment_changed' => 'أُعيد توزيع الطبلون بعد تنزيله؛ تُحفظ القراءة للمراجعة',
        'outside_reading_day' => 'سُجّلت القراءة في غير يوم القراءة',
        'untrusted_clock' => 'وقت الجهاز لا يطابق وقت الخادم',
        'late_sync' => 'وصلت القراءة بعد انتهاء مهلة المزامنة',
        'cycle_closed' => 'دورة القراءة مغلقة',
        'week_locked' => 'يمكن حفظ قراءات الأسبوع الأخير فقط؛ الأسابيع السابقة للعرض فقط.',
        'already_read' => 'للمشترك قراءة في هذا الأسبوع',
        'later_week_read' => 'للمشترك قراءة لأسبوع لاحق',
        'below_previous' => 'القراءة أقل من القراءة السابقة',
        'impossible_consumption' => 'الاستهلاك أكبر من أي استهلاك أسبوعي ممكن',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReadingSubmissionStatus::class,
            'current_reading' => 'float',
            'captured_at' => 'datetime',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReadingCycle::class, 'reading_cycle_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ReadingAssignment::class, 'reading_assignment_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function meterReading(): BelongsTo
    {
        return $this->belongsTo(MeterReading::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function issueMessage(): ?string
    {
        return $this->issue === null ? null : (self::ISSUES[$this->issue] ?? $this->issue);
    }
}
