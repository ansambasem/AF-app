<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionStatus;
use App\Models\MeterReading;
use App\Models\Subscription;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMeterReadingRequest extends FormRequest
{
    private ?Subscription $subscription = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can($this->creationAbility(), MeterReading::class);
    }

    protected function creationAbility(): string
    {
        return 'createOnReadingDay';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Only active subscriptions in the actor's own branch (any branch for a
     * Super Admin) can have readings recorded.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $actor = $this->user();

        return [
            'subscription_id' => [
                'required',
                Rule::exists('subscriptions', 'id')
                    ->where('status', SubscriptionStatus::Active->value)
                    ->when(! $actor->isSuperAdmin(), fn ($rule) => $rule->where('branch_id', $actor->workBranchId())),
            ],
            'week_start' => ['required', 'date', 'before_or_equal:today'],
            'current_reading' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Readings must be entered week after week, once the week has ended:
     * only for the latest week (any week for the Super Admin), one per
     * subscription per week, never before a week already recorded, and never
     * lower than the reading the week starts from.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $subscription = $this->subscription();
                $weekStart = $this->weekStart();

                if ($weekStart->greaterThan(MeterReading::latestEndedWeekStart($subscription->branch_id))) {
                    $validator->errors()->add('week_start', 'لا يمكن إدخال قراءة لأسبوع لم ينتهِ بعد.');

                    return;
                }

                if (! $this->user()->can($this->creationAbility(), [MeterReading::class, $weekStart])) {
                    $validator->errors()->add('week_start', 'يمكن إدخال قراءات الأسبوع الأخير فقط؛ الأسابيع السابقة للعرض فقط.');

                    return;
                }

                $latestWeekStart = $subscription->meterReadings()->max('week_start');

                if ($latestWeekStart !== null && Carbon::parse($latestWeekStart)->gte($weekStart)) {
                    $validator->errors()->add('week_start', Carbon::parse($latestWeekStart)->equalTo($weekStart)
                        ? 'تم تسجيل قراءة لهذا المشترك في هذا الأسبوع مسبقًا.'
                        : 'يوجد قراءة لأسبوع لاحق لهذا المشترك، لا يمكن إدخال أسبوع سابق.');

                    return;
                }

                if ($subscription->meterReadings()->where('requires_reentry', true)->exists()) {
                    $validator->errors()->add('current_reading', 'أدخل القراءة الحالية المعلّقة أولًا قبل تسجيل أسبوع جديد.');

                    return;
                }

                $previousReading = $subscription->previousReadingBefore($weekStart);

                if ($this->float('current_reading') < $previousReading) {
                    $validator->errors()->add('current_reading', "القراءة الحالية لا يمكن أن تكون أقل من القراءة السابقة ({$previousReading}).");

                    return;
                }

                $consumption = MeterReading::consumptionBetween($previousReading, $this->float('current_reading'));

                if (MeterReading::isImpossibleConsumption($consumption)) {
                    $validator->errors()->add('current_reading', self::impossibleConsumptionMessage($consumption));
                }
            },
        ];
    }

    /**
     * Why a consumption above what any subscription could use in a week is refused.
     */
    public static function impossibleConsumptionMessage(float $consumption): string
    {
        $limit = number_format((float) config('powercollect.readings.max_weekly_kwh'), 0, '.', '');

        return "الاستهلاك ({$consumption} ك.و.س) أكبر من أي استهلاك أسبوعي ممكن (الحد {$limit} ك.و.س)؛ تأكد من القراءة المُدخلة.";
    }

    /**
     * The first day of the week the submitted date falls in, by the reading
     * schedule of the subscription's branch.
     */
    public function weekStart(): Carbon
    {
        return MeterReading::weekStartFor(Carbon::parse($this->input('week_start')), $this->subscription()->branch_id);
    }

    private function subscription(): Subscription
    {
        return $this->subscription ??= Subscription::findOrFail($this->integer('subscription_id'));
    }
}
