<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Jobs\SendOutboundMessage;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /**
     * Run Meta send immediately so delivery works without a queue worker (QUEUE_CONNECTION=database).
     */
    private function dispatchOutboundNow(int $messageId): Message
    {
        SendOutboundMessage::dispatchSync($messageId);

        return Message::query()->findOrFail($messageId);
    }

    private function displayTimezone(Request $request): string
    {
        return (string) ($request->header('X-Timezone') ?: config('app.display_timezone', 'UTC'));
    }

    private function formatInTz(?CarbonInterface $dt, string $tz): ?string
    {
        return $dt ? $dt->copy()->timezone($tz)->toISOString() : null;
    }

    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $query = Conversation::with('contact')->orderByDesc('last_message_at');

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $digits = preg_replace('/\D+/', '', $search);

            $query->whereHas('contact', function ($q) use ($search, $digits) {
                $q->where(function ($inner) use ($search, $digits) {
                    $inner->where('phone_number', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%')
                        ->orWhere('profile_name', 'like', '%'.$search.'%');
                    if ($digits !== '') {
                        $inner->orWhere('phone_number', 'like', '%'.$digits.'%');
                    }
                });
            });
        }

        $conversations = $query->paginate($perPage);

        // Attach automation mode (auto/manual) from conversation_states keyed by contact phone.
        // This avoids extra API calls from the UI and keeps the inbox list informative.
        $phones = $conversations->getCollection()
            ->map(fn ($c) => $c->contact?->phone_number)
            ->filter(fn ($p) => is_string($p) && trim($p) !== '')
            ->map(fn ($p) => trim((string) $p))
            ->values()
            ->all();

        $modesByPhone = $phones !== []
            ? ConversationState::query()
                ->whereIn('phone', $phones)
                ->pluck('mode', 'phone')
                ->all()
            : [];

        $conversations->getCollection()->transform(function ($c) use ($modesByPhone) {
            $phone = $c->contact?->phone_number;
            $phone = is_string($phone) ? trim($phone) : '';
            $c->setAttribute('automation_mode', $phone !== '' ? ($modesByPhone[$phone] ?? null) : null);
            return $c;
        });

        return response()->json([
            'data' => ConversationResource::collection($conversations->getCollection()),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'contact_id' => 'required|exists:contacts,id',
        ]);

        $conversation = Conversation::create([
            'contact_id' => $data['contact_id'],
            'status' => 'open',
            'window_expires_at' => null,
        ]);

        return response()->json([
            'message' => 'Conversation created',
            'data' => $conversation,
        ], 201);
    }

    public function show(int $id)
    {
        $conversation = Conversation::with('contact')->findOrFail($id);

        $phone = $conversation->contact?->phone_number;
        $phone = is_string($phone) ? trim($phone) : '';
        if ($phone !== '') {
            $conversation->setAttribute(
                'automation_mode',
                ConversationState::query()->where('phone', $phone)->value('mode')
            );
        } else {
            $conversation->setAttribute('automation_mode', null);
        }

        return response()->json([
            'data' => new ConversationResource($conversation),
        ]);
    }

    public function markAsRead(int $id)
    {
        $conversation = Conversation::with('contact')->findOrFail($id);

        $conversation->forceFill([
            'unread_inbound_count' => 0,
            'last_read_at' => now(),
        ])->save();

        return response()->json([
            'data' => new ConversationResource($conversation->fresh(['contact'])),
        ]);
    }

    public function messages(Request $request, int $id)
    {
        $conversation = Conversation::findOrFail($id);

        $messages = Message::where('conversation_id', $id)
            ->with('sentByUser')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50);

        return response()->json([
            'data' => MessageResource::collection($messages->getCollection()),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    public function sendMessage(Request $request, int $id)
    {
        $conversation = Conversation::with('contact')->findOrFail($id);

        // Allow explicit template sends even inside the 24h window.
        if ($request->filled('template_name') || $conversation->mustUseTemplate()) {
            $data = $request->validate([
                'template_name' => 'required|string',
                'template_components' => 'nullable|array',
                'template_components.*.type' => 'required_with:template_components|string|in:body,header,footer,button',
                'template_components.*.parameters' => 'nullable|array',
                'template_components.*.parameters.*.type' => 'required_with:template_components.*.parameters|string|in:text,currency,date_time,document,image,video',
                'template_components.*.parameters.*.text' => 'nullable|string',
                'template_components.*.parameters.*.currency' => 'nullable|array',
                'template_components.*.parameters.*.date_time' => 'nullable|array',
                'template_components.*.parameters.*.image' => 'nullable|array',
                'template_components.*.parameters.*.video' => 'nullable|array',
                'template_components.*.parameters.*.document' => 'nullable|array',
            ]);

            $template = MessageTemplate::where('name', $data['template_name'])->first();
            $content = $template?->content ?? $data['template_name'];
                
            if (!empty($data['template_components'])) {
                foreach ($data['template_components'] as $component) {
                    $params = $component['parameters'] ?? [];
                    $paramIndex = 1;
                    foreach ($params as $param) {
                        $key = $param['key'] ?? $paramIndex;
                        $value = $param['text'] ?? $param['value'] ?? '{{' . $key . '}}';
                        $content = str_replace('{{' . $key . '}}', $value, $content);
                        $paramIndex++;
                    }
                }
            }

            $user = $request->user();
            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact->id,
                'meta_message_id' => null,
                'direction' => 'outbound',
                'sender_kind' => 'agent',
                'sent_by_user_id' => $user?->id,
                'type' => 'template',
                'content' => $content,
                'template_name' => $data['template_name'],
                'template_components' => $data['template_components'] ?? null,
                'status' => 'queued',
                'sent_at' => null,
            ]);

            $msg = $this->dispatchOutboundNow($msg->id);

            return response()->json([
                'message' => $msg->status === 'sent' ? 'Template sent successfully' : 'Template failed to send',
                'id' => $msg->id,
                'status' => $msg->status,
                'meta_message_id' => $msg->meta_message_id,
            ]);
        }

        $type = $request->input('type', 'text');

        if ($type === 'interactive_list') {
            $data = $request->validate([
                'type' => 'required|in:interactive_list',
                'body' => 'required|string',
                'button_label' => 'required|string|max:20',
                'sections' => 'required|array|min:1|max:10',
                'sections.*.title' => 'required|string|max:24',
                'sections.*.rows' => 'required|array|min:1|max:10',
                'sections.*.rows.*.id' => 'required|string|max:200',
                'sections.*.rows.*.title' => 'required|string|max:24',
                'sections.*.rows.*.description' => 'nullable|string|max:72',
            ]);

            $interactive = [
                'type' => 'list',
                'body' => ['text' => $data['body']],
                'action' => [
                    'button' => $data['button_label'],
                    'sections' => $data['sections'],
                ],
            ];

            $user = $request->user();
            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact->id,
                'meta_message_id' => null,
                'direction' => 'outbound',
                'sender_kind' => 'agent',
                'sent_by_user_id' => $user?->id,
                'type' => 'text',
                'content' => $data['body'],
                'interactive_payload' => $interactive,
                'status' => 'queued',
                'sent_at' => null,
            ]);

            $msg = $this->dispatchOutboundNow($msg->id);

            return response()->json([
                'message' => $msg->status === 'sent' ? 'Interactive list sent successfully' : 'Interactive list failed to send',
                'id' => $msg->id,
                'status' => $msg->status,
                'meta_message_id' => $msg->meta_message_id,
            ]);
        }

        if ($type === 'interactive_buttons') {
            $data = $request->validate([
                'type' => 'required|in:interactive_buttons',
                'body' => 'required|string',
                'buttons' => 'required|array|min:1|max:3',
                'buttons.*.id' => 'required|string|max:200',
                'buttons.*.title' => 'required|string|max:20',
            ]);

            $interactive = [
                'type' => 'button',
                'body' => ['text' => $data['body']],
                'action' => [
                    'buttons' => array_map(
                        fn ($b) => ['type' => 'reply', 'reply' => ['id' => $b['id'], 'title' => $b['title']]],
                        $data['buttons']
                    ),
                ],
            ];

            $user = $request->user();
            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact->id,
                'meta_message_id' => null,
                'direction' => 'outbound',
                'sender_kind' => 'agent',
                'sent_by_user_id' => $user?->id,
                'type' => 'text',
                'content' => $data['body'],
                'interactive_payload' => $interactive,
                'status' => 'queued',
                'sent_at' => null,
            ]);

            $msg = $this->dispatchOutboundNow($msg->id);

            return response()->json([
                'message' => $msg->status === 'sent' ? 'Interactive buttons sent successfully' : 'Interactive buttons failed to send',
                'id' => $msg->id,
                'status' => $msg->status,
                'meta_message_id' => $msg->meta_message_id,
            ]);
        }

        $data = $request->validate([
            'message' => 'required|string',
        ]);

        $user = $request->user();
        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact->id,
            'meta_message_id' => null,
            'direction' => 'outbound',
            'sender_kind' => 'agent',
            'sent_by_user_id' => $user?->id,
            'type' => 'text',
            'content' => $data['message'],
            'status' => 'queued',
            'sent_at' => null,
        ]);

        $msg = $this->dispatchOutboundNow($msg->id);

        return response()->json([
            'message' => $msg->status === 'sent' ? 'Message sent successfully' : 'Message failed to send',
            'id' => $msg->id,
            'status' => $msg->status,
            'meta_message_id' => $msg->meta_message_id,
        ]);
    }
}
