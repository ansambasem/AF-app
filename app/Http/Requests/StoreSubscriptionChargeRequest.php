<?php

namespace App\Http\Requests;

use App\Enums\ChargeType;
use App\Models\Closing;
use App\Models\Subscription;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSubscriptionChargeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('addCharge', $this->route('subscription'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...self::chargeRules($this->input('type')),
            'override_minimum_amount' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->input('type') !== ChargeType::MinimumCharge->value) {
                return;
            }

            /** @var Subscription $subscription */
            $subscription = $this->route('subscription');
            $minimum = $subscription->weeklyMinimumPayment();
            if (Closing::cents($minimum) <= 0) {
                $validator->errors()->add('amount', 'لا يوجد حد أدنى موجب محدد لهذا المشترك.');
            } elseif (! $this->boolean('override_minimum_amount') && Closing::cents($this->input('amount')) !== Closing::cents($minimum)) {
                $validator->errors()->add('amount', 'يجب أن يساوي المبلغ الحد الأدنى الحالي للمشترك. حدّث الصفحة وحاول مجددًا.');
            }
        }];
    }

    /**
     * The charge's rules, for a charge of `$type`; shared with correcting a
     * charge. A subscription fee has the same limit as at registration.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function chargeRules(mixed $type = null): array
    {
        $most = $type === ChargeType::SubscriptionFee->value ? config('powercollect.limits.subscription_fee') : 1000000;

        return [
            'type' => ['required', Rule::enum(ChargeType::class)],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.$most],
            'notes' => ['required_if:type,'.ChargeType::Penalty->value, 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::chargeMessages();
    }

    /**
     * @return array<string, string>
     */
    public static function chargeMessages(): array
    {
        return ['notes.required_if' => 'اكتب سبب الغرامة؛ يظهر في كشف حساب المشترك.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['type' => 'نوع التحميل', 'notes' => 'التفاصيل'];
    }
}
