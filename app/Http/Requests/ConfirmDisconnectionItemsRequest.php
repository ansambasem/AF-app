<?php

namespace App\Http\Requests;

use App\Enums\PermissionKey;
use App\Models\DisconnectionListItem;
use Illuminate\Contracts\Validation\ValidationRule;

class ConfirmDisconnectionItemsRequest extends DisconnectionItemsRequest
{
    /** The most disconnections one confirmation may reach. */
    public const MAX_CONFIRMED = 500;

    protected function ability(): string
    {
        return 'execute';
    }

    /**
     * The items confirmed, each with the fee to charge it, and the explicit
     * statement that the disconnection was carried out.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_CONFIRMED],
            'ids.*' => ['integer', 'distinct'],
            'fees' => ['required', 'array'],
            'fees.*' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],
            'confirmed' => ['accepted'],
        ];
    }

    /**
     * The fee to charge the item: the one chosen for it by someone allowed
     * to change disconnection fees, otherwise the usual fee.
     */
    public function feeFor(int $itemId): string
    {
        $fee = $this->validated('fees')[$itemId] ?? null;

        if ($fee === null || ! $this->user()->hasPermission(PermissionKey::ChangeDisconnectionFee)) {
            return DisconnectionListItem::usualFee();
        }

        return (string) $fee;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'ids.max' => 'يمكن تأكيد قطع '.self::MAX_CONFIRMED.' مشترك على الأكثر في المرة الواحدة.',
            'confirmed.accepted' => 'أكّد أن القطع نُفّذ فعلًا للمشتركين المحددين.',
            'fees.required' => 'افتح المعاينة قبل التأكيد.',
            'fees.*.required' => 'أدخل رسوم القطع لكل مشترك (0 للإعفاء).',
            'fees.*.numeric' => 'رسوم القطع يجب أن تكون رقمًا.',
            'fees.*.decimal' => 'رسوم القطع بخانتين عشريتين على الأكثر.',
            'fees.*.min' => 'رسوم القطع لا تقل عن 0.',
            'fees.*.max' => 'رسوم القطع لا تزيد عن :max شيكل.',
        ];
    }
}
