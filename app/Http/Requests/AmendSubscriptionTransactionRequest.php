<?php

namespace App\Http\Requests;

use App\Models\Bank;
use App\Models\SubscriptionTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Change only a payment's descriptive details, with an audit reason. */
class AmendSubscriptionTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('amend', $this->line());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...self::bankRules($this->line()->payment_method?->throughBank() ?? false),
            'reference_number' => $this->line()->payment_method?->throughBank()
                ? ['sometimes', 'required', 'string', 'max:100'] : ['prohibited'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'amendment_reason' => ['required', 'string', 'max:1000'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'exchange_rate' => ['prohibited'],
            'payment_method' => ['prohibited'],
            'type' => ['prohibited'],
            'subscription_id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'voucher_number' => ['prohibited'],
            'manual_voucher_number' => ['prohibited'],
            'cash_box' => ['prohibited'],
        ];
    }

    /**
     * The rules for the bank fields of a payment's details: a transfer names
     * one of the banks it was made to (and optionally from), and a cash
     * payment has none of them.
     *
     * @return array<string, array<mixed>>
     */
    public static function bankRules(bool $throughBank): array
    {
        return [
            'bank_name' => $throughBank
                ? ['required', Rule::in(Bank::receiverNames())]
                : ['prohibited'],
            'sender_bank_name' => $throughBank
                ? ['nullable', Rule::in(Bank::senderNames())]
                : ['prohibited'],
            'sender_name' => $throughBank ? ['nullable', 'string', 'max:255'] : ['prohibited'],
            'bank_received_on' => $throughBank ? ['sometimes', ...StoreSubscriptionPaymentRequest::bankReceivedDateRules()] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bank_name' => 'البنك المستلم',
            'bank_received_on' => 'تاريخ وصول الحوالة إلى البنك',
            'sender_bank_name' => 'البنك المرسل',
            'sender_name' => 'اسم المرسل',
            'reference_number' => 'الرقم المرجعي',
            'notes' => 'الملاحظات',
            'amendment_reason' => 'سبب التعديل',
            'amount' => 'المبلغ',
            'currency' => 'العملة',
            'payment_method' => 'طريقة الدفع',
        ];
    }

    private function line(): SubscriptionTransaction
    {
        return $this->route('transaction');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return StoreSubscriptionPaymentRequest::paymentMessages();
    }
}
