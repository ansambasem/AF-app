<?php

namespace App\Http\Requests;

use App\Enums\DiscountMethod;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSubscriptionDiscountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('addDiscount', $this->route('subscription'));
    }

    /**
     * A one-off discount is given in shekels only: `value` is the shekels
     * taken off the balance. Percentages and free kilowatts belong to the
     * weekly readings discount.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::discountRules();
    }

    /**
     * The discount's rules; shared with correcting a discount.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function discountRules(): array
    {
        return [
            'method' => ['required', Rule::in([DiscountMethod::Shekel->value])],
            'value' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * A discount only lowers what the subscription owes: never below zero,
     * so it can't be given when nothing is owed.
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

                /** @var Subscription $subscription */
                $subscription = $this->route('subscription');

                self::checkAgainstBalance($validator, $subscription, $subscription->balance(), $this->input('method'), $this->input('value'));
            },
        ];
    }

    /**
     * Add an error when the discount would take `$owed` below zero, or
     * nothing is owed; shared with correcting a discount, where `$owed`
     * leaves out the discount being corrected.
     */
    public static function checkAgainstBalance(Validator $validator, Subscription $subscription, float $owed, string $method, float|string $value): void
    {
        if ($owed <= 0) {
            $validator->errors()->add('value', 'لا يوجد رصيد مستحق على المشترك ليُخصم منه.');

            return;
        }

        $method = DiscountMethod::from($method);
        $discount = SubscriptionTransaction::discountFor($method, $value, match ($method) {
            DiscountMethod::Percentage => $owed,
            DiscountMethod::Kilowatt => $subscription->kilowattPrice(),
            DiscountMethod::Shekel => null,
        });

        if ($discount < 0.01) {
            $validator->errors()->add('value', 'قيمة الخصم أقل من أن تُسجَّل.');
        } elseif ($discount > $owed) {
            $validator->errors()->add('value', sprintf(
                'لا يمكن أن يزيد الخصم (%s شيكل) عن الرصيد المستحق (%s شيكل).',
                SubscriptionTransaction::formatAmount($discount),
                SubscriptionTransaction::formatAmount($owed),
            ));
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::discountMessages();
    }

    /**
     * The discount's messages; shared with correcting a discount.
     *
     * @return array<string, string>
     */
    public static function discountMessages(): array
    {
        return ['method.in' => 'الخصم لمرة واحدة يكون بالشيكل فقط.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['method' => 'طريقة الخصم', 'value' => 'قيمة الخصم', 'notes' => 'التفاصيل'];
    }
}
