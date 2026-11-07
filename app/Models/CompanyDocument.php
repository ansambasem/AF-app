<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Database\Factories\CompanyDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['operation_id', 'company_form_template_id', 'subscription_id', 'branch_id', 'created_by', 'mode', 'title', 'paper_reference', 'paper_date', 'notes', 'snapshot', 'attachment_disk', 'attachment_path', 'attachment_name', 'attachment_mime'])]
class CompanyDocument extends Model
{
    /** @use HasFactory<CompanyDocumentFactory> */
    use BelongsToBranch, HasFactory;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'paper_date' => 'date'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
