<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMobileSubscriberMessageRequest;
use App\Jobs\SendSubscriptionMessage;
use App\Models\MessageBatch;
use App\Models\Subscription;
use App\Models\SubscriptionMessage;
use App\Support\Messaging\MessageComposer;
use App\Support\Messaging\SmsGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileSubscriberMessageController extends Controller
{
    public function index(Request $request, SmsGateway $gateway): JsonResponse
    {
        $this->authorize('viewAny', MessageBatch::class);
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $subscribers = Subscription::query()->visibleTo($request->user())
            ->matchingSearch(trim($validated['search'] ?? ''))->orderBy('full_name')->orderBy('id')
            ->paginate(25, ['id', 'full_name', 'subscription_name', 'account_number', 'phone', 'subscription_phone']);

        return response()->json([
            'data' => $subscribers->getCollection()->map(fn (Subscription $subscriber): array => [
                'id' => $subscriber->id, 'full_name' => $subscriber->displayName(),
                'account_number' => $subscriber->account_number, 'phone' => $subscriber->contactPhone(),
            ]),
            'current_page' => $subscribers->currentPage(), 'last_page' => $subscribers->lastPage(),
            'total' => $subscribers->total(), 'sms_available' => $gateway->deliversMessages(),
            'can_send' => $request->user()->can('create', MessageBatch::class),
            'can_view_history' => $request->user()->can('viewAny', MessageBatch::class),
        ]);
    }

    public function store(StoreMobileSubscriberMessageRequest $request, string $subscription, MessageComposer $composer, SmsGateway $gateway): JsonResponse
    {
        $subscription = Subscription::query()->visibleTo($request->user())
            ->with(['branch', 'meterBox'])->withSum('transactions as balance', 'amount')->findOrFail($subscription);
        $validated = $request->validated();
        $message = DB::transaction(function () use ($request, $subscription, $validated, $composer, $gateway): SubscriptionMessage {
            // Serialize retries for this recipient before checking the operation identifier.
            $subscription->newQuery()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $existing = MessageBatch::query()->where('operation_id', $validated['mobile_operation_id'])->first();
            if ($existing) {
                abort_unless($existing->created_by === $request->user()->id && $existing->branch_id === $subscription->branch_id, 403);
                $message = $existing->messages()->where('subscription_id', $subscription->id)->first();
                abort_unless($message && $existing->body === $validated['body'] && $existing->channel === MessageChannel::Sms, 409, 'تغيّر نص الرسالة. افتح رسالة جديدة.');

                return $message;
            }
            if (blank($subscription->contactPhone())) {
                throw ValidationException::withMessages(['phone' => 'لا يوجد رقم هاتف لهذا المشترك. حدّث بياناته أولاً.']);
            }
            if (! $gateway->deliversMessages()) {
                throw ValidationException::withMessages(['sms' => 'إرسال الرسائل النصية غير مفعّل. تواصل مع الإدارة لتفعيل خدمة SMS.']);
            }
            $batch = MessageBatch::query()->firstOrCreate(['operation_id' => $validated['mobile_operation_id']], [
                'branch_id' => $subscription->branch_id,
                'created_by' => $request->user()->id,
                'kind' => MessageKind::Custom,
                'channel' => MessageChannel::Sms,
                'body' => $validated['body'],
            ]);
            if (! $batch->wasRecentlyCreated) {
                abort_unless($batch->created_by === $request->user()->id && $batch->branch_id === $subscription->branch_id, 403);
                $message = $batch->messages()->where('subscription_id', $subscription->id)->first();
                abort_unless($message && $batch->body === $validated['body'] && $batch->channel === MessageChannel::Sms, 409, 'تغيّر نص الرسالة. افتح رسالة جديدة.');

                return $message;
            }
            $message = $batch->messages()->create([
                'subscription_id' => $subscription->id,
                'branch_id' => $subscription->branch_id,
                'phone' => $subscription->contactPhone(),
                'body' => $composer->render($validated['body'], $composer->variables($subscription, MessageKind::Custom)),
                'status' => MessageStatus::Pending,
            ]);
            SendSubscriptionMessage::dispatch($message)->afterCommit();

            return $message;
        });

        $message->refresh();

        return response()->json([
            'id' => $message->id,
            'status' => $message->status->value,
            'phone' => $message->phone,
            'error' => $message->error,
        ], 201);
    }
}
