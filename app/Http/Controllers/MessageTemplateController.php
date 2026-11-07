<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageTemplateRequest;
use App\Models\MessageTemplate;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Saved wordings for messages to subscriptions, kept from the compose page.
 */
class MessageTemplateController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMessageTemplateRequest $request): RedirectResponse
    {
        $template = MessageTemplate::create([...$request->validated(), 'created_by' => $request->user()->id]);
        $request->user()->notify(new ActionCompleted('message-template-created', $template->name));

        return back()->with('status', 'message-template-created');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreMessageTemplateRequest $request, MessageTemplate $messageTemplate): RedirectResponse
    {
        $messageTemplate->update($request->validated());
        $request->user()->notify(new ActionCompleted('message-template-updated', $messageTemplate->name));

        return back()->with('status', 'message-template-updated');
    }

    /**
     * Remove the specified resource from storage. Sends keep their own copy
     * of the wording, so nothing else depends on it.
     */
    public function destroy(Request $request, MessageTemplate $messageTemplate): RedirectResponse
    {
        $this->authorize('delete', $messageTemplate);

        $messageTemplate->delete();
        $request->user()->notify(new ActionCompleted('message-template-deleted', $messageTemplate->name));

        return back()->with('status', 'message-template-deleted');
    }
}
