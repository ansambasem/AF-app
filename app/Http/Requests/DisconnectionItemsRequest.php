<?php

namespace App\Http\Requests;

use App\Models\DisconnectionList;
use App\Models\DisconnectionListItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An action on some of a disconnection list's items, named by id — the ones
 * its preview showed, so it reaches exactly what the person saw. Ids that
 * are not on the list are left out.
 */
abstract class DisconnectionItemsRequest extends FormRequest
{
    /** The most items one action may reach. */
    public const MAX_ITEMS = 2000;

    /**
     * The policy ability the action takes on the list.
     */
    abstract protected function ability(): string;

    public function authorize(): bool
    {
        return $this->user()->can($this->ability(), $this->disconnectionList());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    public function disconnectionList(): DisconnectionList
    {
        return $this->route('disconnectionList');
    }

    /**
     * The chosen items on the list, in the order they were chosen.
     *
     * @return Collection<int, DisconnectionListItem>
     */
    public function items(): Collection
    {
        $ids = array_map('intval', $this->validated('ids'));

        return $this->disconnectionList()->items()
            ->whereIn('id', $ids)
            ->with('subscription')
            ->get()
            ->sortBy(fn (DisconnectionListItem $item): int => array_search($item->id, $ids, true))
            ->values();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'اختر مشتركًا واحدًا على الأقل من القائمة.',
            'ids.max' => 'يمكن تطبيق الإجراء على '.self::MAX_ITEMS.' مشترك على الأكثر في المرة الواحدة؛ ضيّق الفلاتر.',
        ];
    }
}
