<?php

namespace App\Models;

use Database\Factories\AuditNoteReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['audit_note_id', 'user_id', 'kind', 'body', 'document_path'])]
class AuditNoteReply extends Model
{
    /** @use HasFactory<AuditNoteReplyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
