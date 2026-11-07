<?php

namespace App\Http\Requests;

use App\Models\Bank;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One bank transfer divided between several subscriptions. Only transfers
 * are split — a cash payment is handed over to one subscription — and the
 * parts must add up to exactly what was transferred.
 */
class StoreSplitPaymentRequest extends FormRequest
{
    /** The most subscriptions one transfer is divided between. */
    public const MAX_PARTS = 20;

    /** @var Collection<int, Subscription>|null */
    private ?Collection $subscriptions = null;

    protected function prepareForValidation(): void
    {
        foreach (['reference_number', 'sender_name'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('recordSplitPayment', Subscription::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'total_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:1000000'],
            'bank_name' => ['required', Rule::in(Bank::receiverNames())],
            'sender_bank_name' => ['nullable', Rule::in(Bank::senderNames())],
            'bank_received_on' => StoreSubscriptionPaymentRequest::bankReceivedDateRules(),
            'sender_name' => ['required', 'string', 'max:255'],
            'reference_number' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Sent once the collector confirmed that the reference is already on another payment.
            'confirm_duplicate_reference' => ['sometimes', 'boolean'],
            // Sent once the collector confirmed an amount far above what a subscription owes.
            'confirm_overpayment' => ['sometimes', 'boolean'],
            'parts' => ['required', 'array', 'min:2', 'max:'.self::MAX_PARTS],
            'parts.*.subscription_id' => ['required', 'integer', 'distinct'],
            'parts.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:1000000'],
        ];
    }

    /**
     * The parts add up to the transfer, each is a subscription the user may
     * take a payment for, and none is far above what its subscription owes
     * unless the collector confirmed it.
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

                $parts = $this->parts();

                foreach ((array) $this->input('parts') as $index => $part) {
                    if (! $this->partSubscriptions()->has((int) $part['subscription_id'])) {
                        $validator->errors()->add("parts.$index.subscription_id", 'هذا المشترك غير متاح لك.');
                    }
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $partsInCents = array_sum(array_map(fn (array $part): int => (int) round((float) $part['amount'] * 100), $parts));

                if ($partsInCents !== (int) round((float) $this->input('total_amount') * 100)) {
                    $validator->errors()->add('parts', sprintf(
                        'مجموع المبالغ الموزَّعة (%s ₪) لا يساوي المبلغ المحوَّل (%s ₪).',
                        SubscriptionTransaction::formatAmount($partsInCents / 100),
                        SubscriptionTransaction::formatAmount($this->input('total_amount')),
                    ));

                    return;
                }

                if ($this->boolean('confirm_overpayment')) {
                    return;
                }

                foreach ($parts as $part) {
                    $owed = max($part['subscription']->balance(), 0.0);

                    if (Subscription::overpaymentNeedsConfirmation((float) $part['amount'], $owed)) {
                        $validator->errors()->add('confirm_overpayment', sprintf(
                            'المبلغ %s ₪ أكبر بكثير من المستحق على %s (%s ₪)، وسيبقى له رصيد دائن؛ تأكد من التوزيع ثم أكّد أنه صحيح.',
                            SubscriptionTransaction::formatAmount($part['amount']),
                            $part['subscription']->displayName(),
                            SubscriptionTransaction::formatAmount($owed),
                        ));
                    }
                }
            },
        ];
    }

    /**
     * Each part's subscription with its amount, in the order entered; call
     * it once the request has passed validation.
     *
     * @return array<int, array{subscription: Subscription, amount: string}>
     */
    public function parts(): array
    {
        return collect((array) $this->input('parts'))
            ->map(fn (array $part): array => ['subscription' => $this->partSubscriptions()->get((int) $part['subscription_id']), 'amount' => (string) $part['amount']])
            ->filter(fn (array $part): bool => $part['subscription'] !== null)
            ->values()
            ->all();
    }

    /**
     * The subscriptions named by the parts that the user may see (and so take
     * a payment for): the branch's own, or any for the Super Admin.
     *
     * @return Collection<int, Subscription>
     */
    private function partSubscriptions(): Collection
    {
        return $this->subscriptions ??= Subscription::query()
            ->visibleTo($this->user())
            ->with('branch')
            ->whereIn('id', collect((array) $this->input('parts'))->pluck('subscription_id')->filter(fn ($id) => is_numeric($id))->map(fn ($id): int => (int) $id))
            ->get()
            ->keyBy('id');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parts.required' => 'أضف المشتركين الذين تُوزَّع عليهم الدفعة.',
            ...StoreSubscriptionPaymentRequest::paymentMessages(),
            'parts.min' => 'اختر مشتركَين على الأقل لتوزيع الدفعة عليهم؛ وإن كانت لمشترك واحد فسجّلها دفعة عادية.',
            'parts.max' => 'لا تُوزَّع الدفعة على أكثر من :max مشتركًا.',
            'parts.*.subscription_id.distinct' => 'هذا المشترك مضاف أكثر من مرة.',
            'parts.*.amount.required' => 'أدخل مبلغ هذا المشترك.',
            'parts.*.amount.gt' => 'يجب أن يكون مبلغ كل مشترك أكبر من صفر.',
            'bank_name.required' => 'اختر البنك أو المحفظة التي حُوّل إليها المبلغ.',
            'bank_name.in' => 'اختر أحد البنوك أو المحافظ المتاحة.',
            'sender_bank_name.in' => 'اختر أحد البنوك أو المحافظ المتاحة للتحويل منه.',
            'sender_name.required' => 'أدخل اسم صاحب الحساب الذي حُوّل منه المبلغ.',
            'reference_number.required' => 'أدخل رقم المرجع؛ به تُطابَق الأجزاء مع سطر البنك.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['total_amount' => 'المبلغ المحوَّل', 'reference_number' => 'رقم المرجع', 'sender_name' => 'اسم المرسل', 'notes' => 'الملاحظات'];
    }
}
