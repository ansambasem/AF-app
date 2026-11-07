<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Models\Bank;
use App\Models\CurrencyRate;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSubscriptionPaymentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('reference_number')) {
            $this->merge(['reference_number' => trim((string) $this->input('reference_number'))]);
        }
        $this->merge(['exchange_rate' => CurrencyRate::forPayment($this->input('currency'))]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('recordPayment', $this->route('subscription'));
    }

    /**
     * A payment keeps its original currency and exchange rate; a bank transfer needs one of
     * the transfer banks and who sent it (its reference number is optional) (the subscription or
     * someone else); the cash box and the paper voucher only apply to cash.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = self::paymentRules($this->input('payment_method'));
        $rules['payment_method'][] = self::allowedMethodRule($this->user());
        foreach (['late_payment', 'paid_on', 'late_reason', 'late_payment_token'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Each way to be paid is its own permission (cash, or a bank transfer):
     * a payment taken another way is refused, with why.
     */
    public static function allowedMethodRule(User $user): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($user): void {
            $method = PaymentMethod::tryFrom((string) $value);

            if ($method !== null && ! in_array($method, $user->allowedPaymentMethods(), true)) {
                $fail($method === PaymentMethod::Cash
                    ? 'لا تملك صلاحية الدفع نقدًا؛ اطلبها من مدير الفرع.'
                    : 'لا تملك صلاحية الدفع بالتحويل البنكي؛ اطلبها من مدير الفرع.');
            }
        };
    }

    /**
     * An amount far above what the subscription owes is a likely slip, so it
     * needs the collector's confirmation.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Subscription $subscription */
                $subscription = $this->route('subscription');

                self::checkOverpayment($validator, self::amountInShekels($this->input('amount'), $this->input('currency'), $this->input('exchange_rate')), max($subscription->balance(), 0.0), $this->boolean('confirm_overpayment'));
            },
        ];
    }

    /**
     * Refuse `$amount`, which is far above what is `$owed`, unless `$confirmed`.
     * Shared with correcting a payment and with the app's.
     */
    public static function checkOverpayment(Validator $validator, mixed $amount, float $owed, bool $confirmed): void
    {
        if ($validator->errors()->isEmpty() && is_numeric($amount) && ((float) $amount < 0.01 || (float) $amount > 1000000)) {
            $validator->errors()->add('amount', 'يجب أن تعادل الدفعة ما بين 0.01 و1,000,000 شيكل بعد التحويل.');
        }
        if ($validator->errors()->isNotEmpty() || $confirmed || ! is_numeric($amount) || ! Subscription::overpaymentNeedsConfirmation((float) $amount, $owed)) {
            return;
        }

        $validator->errors()->add('confirm_overpayment', sprintf(
            'المبلغ %s ₪ أكبر بكثير من المستحق على المشترك (%s ₪)، وسيبقى له رصيد دائن قدره %s ₪؛ تأكد من المبلغ ثم أكّد أنه صحيح.',
            SubscriptionTransaction::formatAmount($amount),
            SubscriptionTransaction::formatAmount($owed),
            SubscriptionTransaction::formatAmount((float) $amount - $owed),
        ));
    }

    public static function amountInShekels(mixed $amount, mixed $currency, mixed $exchangeRate): ?float
    {
        $rate = $currency === Currency::Shekel->value ? 1 : $exchangeRate;

        return is_numeric($amount) && is_numeric($rate) ? round((float) $amount * (float) $rate, 2) : null;
    }

    /**
     * The payment's rules, for a payment made by `$paymentMethod`; shared
     * with correcting a payment.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function paymentRules(mixed $paymentMethod): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:1000000'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'exchange_rate' => ['exclude_if:currency,ILS', 'required_unless:currency,ILS', 'numeric', 'decimal:0,4', 'gt:0', 'max:10000'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)->only(PaymentMethod::offered())],
            'bank_name' => ['exclude_unless:payment_method,'.PaymentMethod::BankTransfer->value, 'required', Rule::in(Bank::receiverNames())],
            'sender_bank_name' => ['exclude_unless:payment_method,'.PaymentMethod::BankTransfer->value, 'nullable', Rule::in(Bank::senderNames())],
            'bank_received_on' => ['exclude_unless:payment_method,'.PaymentMethod::BankTransfer->value, ...self::bankReceivedDateRules()],
            'sender_name' => ['exclude_unless:payment_method,'.PaymentMethod::BankTransfer->value, 'required', 'string', 'max:255'],
            'reference_number' => ['exclude_if:payment_method,'.PaymentMethod::Cash->value, 'nullable', 'string', 'max:100'],
            // Sent once the collector confirmed that the reference is already on another payment.
            'confirm_duplicate_reference' => ['sometimes', 'boolean'],
            // Sent once the collector confirmed an amount far above what is owed.
            'confirm_overpayment' => ['sometimes', 'boolean'],
            'cash_box' => ['exclude_unless:payment_method,'.PaymentMethod::Cash->value, 'nullable', 'string', 'max:20'],
            'manual_voucher_number' => ['exclude_unless:payment_method,'.PaymentMethod::Cash->value, 'nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...self::paymentMessages(),
            'late_payment.prohibited' => 'أُلغي التسجيل المتأخر. سجّل دفعة عادية وحدد تاريخ وصول الحوالة إلى البنك عند الحاجة.',
        ];
    }

    /** @return array<int, string> */
    public static function bankReceivedDateRules(): array
    {
        return ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now(config('app.business_timezone'))->toDateString()];
    }

    /**
     * @return array<string, string>
     */
    public static function paymentMessages(): array
    {
        return [
            'bank_received_on.date_format' => 'أدخل تاريخ وصول الحوالة إلى البنك بشكل صحيح.',
            'bank_received_on.before_or_equal' => 'تاريخ وصول الحوالة إلى البنك لا يمكن أن يكون بعد اليوم.',
            'currency.enum' => 'اختر الشيكل أو الدولار أو الدينار أو اليورو.',
            'exchange_rate.required_unless' => 'لم يُضبط سعر صرف هذه العملة في صفحة العملات وأسعار الصرف.',
            'exchange_rate.gt' => 'يجب أن يكون سعر الصرف أكبر من صفر.',
            'bank_name.required' => 'اختر البنك أو المحفظة التي حُوّل إليها المبلغ.',
            'bank_name.in' => 'اختر أحد البنوك أو المحافظ المتاحة.',
            'sender_bank_name.in' => 'اختر أحد البنوك أو المحافظ المتاحة للتحويل منه.',
            'sender_name.required' => 'أدخل اسم صاحب الحساب الذي حُوّل منه المبلغ.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['notes' => 'الملاحظات'];
    }
}
