<?php

namespace App\Models;

use App\Enums\MessageKind;
use Database\Factories\MessageTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved wording for messages to subscriptions, picked when writing a new
 * one. Its `{placeholders}` are filled in for each subscription (see
 * App\Support\Messaging\MessageComposer).
 */
#[Fillable(['name', 'kind', 'body', 'created_by'])]
class MessageTemplate extends Model
{
    /** @use HasFactory<MessageTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => MessageKind::class,
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
