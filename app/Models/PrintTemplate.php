<?php

namespace App\Models;

use Database\Factories\PrintTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A saved print design, shared by the whole company: the print designer's
 * whole layout (paper, heading, columns, sorting, grouping, footer) for
 * one list. One template per list may be its default, the design the
 * list's print button starts from.
 */
#[Fillable(['page', 'name', 'layout', 'is_default', 'created_by', 'updated_by'])]
class PrintTemplate extends Model
{
    /** @use HasFactory<PrintTemplateFactory> */
    use HasFactory;

    /**
     * The lists that can be printed, by their address, with the name and
     * icon the templates page shows them under.
     *
     * @var array<string, array{label: string, icon: string}>
     */
    public const PAGES = [
        '/meter-readings' => ['label' => 'القراءات الأسبوعية', 'icon' => 'chart'],
        '/subscriptions' => ['label' => 'المشتركون', 'icon' => 'users'],
        '/meter-boxes' => ['label' => 'الطبلونات', 'icon' => 'table'],
        '/meter-boxes/subscriptions-print' => ['label' => 'الطبلونات ومشتركوها', 'icon' => 'users'],
        '/ledger' => ['label' => 'السجل المالي', 'icon' => 'ledger'],
        '/reports' => ['label' => 'التقارير المالية', 'icon' => 'trend'],
        '/receivables' => ['label' => 'أعمار الديون', 'icon' => 'wallet'],
        '/transaction-audit' => ['label' => 'سجل التدقيق', 'icon' => 'history'],
        '/users' => ['label' => 'المستخدمون', 'icon' => 'user'],
        '/branches' => ['label' => 'الفروع', 'icon' => 'pin'],
        '/circuit-breakers' => ['label' => 'القواطع', 'icon' => 'bolt'],
        '/messages' => ['label' => 'الرسائل', 'icon' => 'messages'],
        '/payments/cash-boxes/report' => ['label' => 'تفاصيل الصندوق المالي', 'icon' => 'wallet'],
        '/subscriptions/statement-print' => ['label' => 'كشف حساب المشترك', 'icon' => 'ledger'],
        '/reading-cycles/print' => ['label' => 'توزيع القراءات', 'icon' => 'layers'],
        '/paper-sheets' => ['label' => 'كشوف القراءات الورقية', 'icon' => 'printer'],
    ];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public static function pageForRequest(Request $request): string
    {
        return $request->routeIs('paper-sheets.show') ? '/paper-sheets' : '/'.trim($request->path(), '/');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Make this the list's default template, in place of any other.
     */
    public function makeDefault(): void
    {
        DB::transaction(function (): void {
            static::query()->where('page', $this->page)->whereKeyNot($this->getKey())->update(['is_default' => false]);
            $this->update(['is_default' => true]);
        });
    }

    /**
     * The first free name for a copy: "name (نسخة)", then "name (نسخة 2)"…
     */
    public function copyName(): string
    {
        $name = $this->name.' (نسخة)';

        for ($number = 2; static::query()->where('page', $this->page)->where('name', $name)->exists(); $number++) {
            $name = $this->name.' (نسخة '.$number.')';
        }

        return $name;
    }

    /**
     * What the print designer needs to list and apply the template.
     *
     * @return array{id: int, name: string, isDefault: bool, layout: array<string, mixed>, updatedBy: ?string, updatedAt: ?string}
     */
    public function toDesigner(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'isDefault' => $this->is_default,
            'layout' => $this->layout,
            'updatedBy' => $this->updatedBy?->name,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
