<?php

namespace App\Http\Requests;

use App\Enums\AccountingType;
use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Models\SubscriberProfile;
use App\Models\Subscription;
use App\Support\DailySeries;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSubscriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Subscription::class)
            && (! $this->filled('source_subscription_id') || $this->sourceSubscription() !== null);
    }

    /** A source subscription must belong to a branch the actor may see. */
    public function sourceSubscription(): ?Subscription
    {
        if (! $this->filled('source_subscription_id') || ! ctype_digit((string) $this->input('source_subscription_id'))) {
            return null;
        }

        return Subscription::query()->visibleTo($this->user())->with('profile')->find($this->input('source_subscription_id'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Identity numbers are unique per shared profile, while the profile can
     * have several subscriptions. Updates keep their existing profile's ID.
     *
     * Only a Super Admin may choose the branch — the controller forces it
     * to the actor's own branch for everyone else, so branch_id isn't
     * validated for them at all.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'full_name' => ['required', 'string', 'max:255'],
            'subscription_name' => ['nullable', 'string', 'max:255'],
            // Optional, for old records brought in without one; when given, a real and unused number.
            'national_id' => ['nullable', 'string', 'regex:/^\d{9}$/', 'not_regex:/^0+$/', Rule::unique('subscriber_profiles', 'national_id')->ignore($this->route('subscription')?->subscriber_profile_id)],
            'phone' => ['nullable', 'string', 'regex:/\A05[69][0-9]{7}\z/'],
            'subscription_phone' => ['nullable', 'string', 'regex:/\A05[69][0-9]{7}\z/'],
            'address' => ['nullable', 'string', 'max:1000'],
            // A subscription is on a box of its own branch: the user's, or the one a Super Admin chose.
            'meter_box_id' => ['nullable', Rule::exists('meter_boxes', 'id')->where('branch_id', $this->boxBranchId())],
            'tariff_id' => ['required', Rule::exists('tariffs', 'id')],
            // Optional, and any customer segment, whatever the tariff.
            'tariff_segment_id' => ['nullable', Rule::exists('tariff_segments', 'id')],
            'status' => ['required', Rule::enum(SubscriptionStatus::class)],
            // Weekly unless chosen otherwise: a request without it keeps the subscription's current type.
            'accounting_type' => ['sometimes', 'required', Rule::enum(AccountingType::class)],
            'circuit_breaker_id' => ['nullable', Rule::exists('circuit_breakers', 'id')],
            'minimum_charge' => ['required', 'numeric', 'min:0', 'max:10000'],
            // Their own kilo price, whether above or below their tariff's; empty means they pay the tariff's.
            'kilowatt_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],
            // The meter's reading when the subscription is connected: it may wait while they are not
            // active yet, but billing counts from it, so an active subscription must have it.
            'initial_reading' => [Rule::requiredIf($this->input('status') === SubscriptionStatus::Active->value), 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'subscription_fee' => ['nullable', 'numeric', 'min:0', 'max:'.config('powercollect.limits.subscription_fee')],
            // Nobody registered before 2000, or on a day still to come.
            'subscription_date' => ['nullable', 'date', 'after_or_equal:2000-01-01', 'before_or_equal:'.DailySeries::today()->toDateString()],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        // A subscription who has been active is disconnected, never put back to waiting.
        if ($this->route('subscription')?->activated_at !== null) {
            $rules['status'][] = Rule::notIn([SubscriptionStatus::Suspended->value]);
        }

        if ($this->user()->isSuperAdmin()) {
            $rules['branch_id'] = ['required', Rule::exists('branches', 'id')];
        }

        // Without the permission the price is never taken from the request (see SubscriptionController).
        if (! $this->user()->hasPermission(PermissionKey::UpdateSubscriptionKilowattPrice)) {
            $rules['kilowatt_price'] = ['exclude'];
        }

        $subscription = $this->route('subscription');

        if (! $subscription) {
            $rules['source_subscription_id'] = ['nullable', 'integer', Rule::exists('subscriptions', 'id')];

            if ($this->filled('source_subscription_id')) {
                foreach (SubscriberProfile::PERSONAL_FIELDS as $field) {
                    $rules[$field] = ['exclude'];
                }
            }
        }

        // A new subscription can be charged the fee, and so can an existing one that has not been yet.
        if (! $subscription || ! $subscription->hasSubscriptionFeeCharge()) {
            $rules['charge_subscription_fee'] = ['sometimes', 'boolean'];

            if ($this->boolean('charge_subscription_fee')) {
                $rules['subscription_fee'] = ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.config('powercollect.limits.subscription_fee')];
            }
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->refuseChangedChargedFee($validator)];
    }

    /**
     * Once the fee is on the account its amount is that line's: changing the
     * field here would leave the two disagreeing, so an edit may only send
     * the same amount back (the form always sends it).
     */
    private function refuseChangedChargedFee(Validator $validator): void
    {
        $subscription = $this->route('subscription');

        if (! $subscription || ! $this->has('subscription_fee') || $validator->errors()->has('subscription_fee') || ! $subscription->hasSubscriptionFeeCharge()) {
            return;
        }

        $stored = $subscription->subscription_fee === null ? null : (float) $subscription->subscription_fee;
        $sent = $this->filled('subscription_fee') ? (float) $this->input('subscription_fee') : null;

        if ($sent !== $stored) {
            $validator->errors()->add('subscription_fee', 'رسوم الاشتراك حُمّلت على الحساب ولا يمكن تغيير مبلغها من هنا.');
        }
    }

    /**
     * The branch the subscription's meter box must be in: the Super Admin's
     * choice, otherwise the user's own.
     */
    private function boxBranchId(): mixed
    {
        return $this->user()->isSuperAdmin() ? $this->input('branch_id') : $this->user()->workBranchId();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'national_id.not_regex' => 'رقم الهوية غير صالح.',
            'meter_box_id.exists' => 'اختر طبلونًا من طبلونات فرع المشترك.',
            'subscription_date.after_or_equal' => 'تاريخ الاشتراك لا يمكن أن يسبق عام 2000.',
            'subscription_date.before_or_equal' => 'تاريخ الاشتراك لا يمكن أن يكون في المستقبل.',
            'subscription_fee.max' => 'رسوم الاشتراك لا تزيد عن :max شيكل.',
            'kilowatt_price.max' => 'سعر الكيلو لا يزيد عن :max شيكل.',
            'subscription_phone.regex' => 'رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 059 أو 056.',
            'status.not_in' => 'لا يمكن إعادة مشترك سبق تفعيله إلى «قيد الانتظار»؛ غيّر حالته إلى «مفصول».',
            'initial_reading.required' => 'أدخل القراءة السابقة قبل تفعيل المشترك؛ منها يبدأ حساب استهلاكه.',
            'national_id.unique' => 'رقم الهوية مسجل بالفعل. استخدم «إضافة اشتراك» من قائمة المشترك لإنشاء اشتراك آخر.',
            'charge_subscription_fee.boolean' => 'اختر تفعيل تحميل رسوم الاشتراك أو إلغاءه.',
            'subscription_fee.required' => 'أدخل مبلغ رسوم الاشتراك عند تفعيل تحميل الرسوم.',
            'subscription_fee.gt' => 'يجب أن تكون رسوم الاشتراك أكبر من صفر عند تحميلها.',
        ];
    }
}
