<?php

namespace App\Models;

use Database\Factories\CompanyFormTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'category', 'layout', 'version', 'source_disk', 'source_path', 'source_name', 'source_mime', 'created_by', 'updated_by'])]
class CompanyFormTemplate extends Model
{
    /** @use HasFactory<CompanyFormTemplateFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['layout' => 'array', 'version' => 'integer'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return array<string, mixed> */
    public function toEditor(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'category' => $this->category,
            'layout' => $this->layout, 'version' => $this->version,
            'source' => $this->source_path ? [
                'name' => $this->source_name, 'mime' => $this->source_mime,
                'url' => route('company-forms.source', $this, absolute: false),
            ] : null,
            'updatedBy' => $this->updatedBy?->name,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
