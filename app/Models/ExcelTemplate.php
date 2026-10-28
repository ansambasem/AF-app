<?php

namespace App\Models;

use Database\Factories\ExcelTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * An Excel layout shared by the whole company, saved from the Excel
 * editor: which parts the file holds, their columns, order, widths and
 * grouping, the totals and the look. One per kind of export may be its
 * default, the layout the editor opens with for whoever has none of their
 * own.
 */
#[Fillable(['kind', 'name', 'settings', 'is_default', 'created_by', 'updated_by'])]
class ExcelTemplate extends Model
{
    /** @use HasFactory<ExcelTemplateFactory> */
    use HasFactory;

    /**
     * The exports that take templates, by the key the editor saves them under.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        'reports' => 'التقارير المالية',
        'tables' => 'جداول الموقع',
        'statements' => 'كشوف الحساب',
        'reading-history' => 'سجل القراءات',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Make this its kind's default template, in place of any other.
     */
    public function makeDefault(): void
    {
        DB::transaction(function (): void {
            static::query()->where('kind', $this->kind)->whereKeyNot($this->getKey())->update(['is_default' => false]);
            $this->update(['is_default' => true]);
        });
    }

    /**
     * What the Excel editor needs to list and apply the template.
     *
     * @return array{id: int, name: string, isDefault: bool, settings: array<string, mixed>, updatedBy: ?string, updatedAt: ?string}
     */
    public function toStudio(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'isDefault' => $this->is_default,
            'settings' => $this->settings,
            'updatedBy' => $this->updatedBy?->name,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
