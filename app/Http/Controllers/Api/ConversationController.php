<?php

namespace App\Http\Controllers\Api;

use App\Events\NewMessageReceived;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Jobs\SendOutboundMessage;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Services\MetaWhatsAppService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(private MetaWhatsAppService $metaService) {}

    private function queueIsSync(): bool
    {
        return (string) config('queue.default') === 'sync';
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
        $conversations = Conversation::with('contact')
            ->orderBy('last_message_at', 'desc')
            ->paginate(20);

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
        $conversation = Conversation::with(['contact', 'messages' => function ($q) {
            $q->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit(50);
        }])->findOrFail($id);

        return response()->json([
            'data' => [
                'conversation' => new ConversationResource($conversation),
                'messages' => MessageResource::collection($conversation->messages),
            ],
        ]);
    }

    public function messages(Request $request, int $id)
    {
        $conversation = Conversation::findOrFail($id);

        $messages = Message::where('conversation_id', $id)
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

            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact->id,
                'meta_message_id' => null,
                'direction' => 'outbound',
                'type' => 'template',
                'content' => $content,
                'template_name' => $data['template_name'],
                'template_components' => $data['template_components'] ?? null,
                'status' => 'queued',
                'sent_at' => null,
            ]);

            if ($this->queueIsSync()) {
                app(SendOutboundMessage::class, ['messageId' => $msg->id])->handle($this->metaService);

                return response()->json([
                    'message' => 'Template sent successfully',
                    'meta_message_id' => Message::find($msg->id)?->meta_message_id,
                ]);
            }

            SendOutboundMessage::dispatch($msg->id);

            return response()->json([
                'message' => 'Template queued',
                'id' => $msg->id,
                'status' => 'queued',
            ], 202);
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

            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact->id,
                'meta_message_id' => null,
                'direction' => 'outbound',
                'type' => 'interactive',
                'content' => $data['body'],
                'interactive_payload' => $interactive,
                'status' => 'queued',
                'sent_at' => null,
            ]);

            if ($this->queueIsSync()) {
                app(SendOutboundMessage::class, ['messageId' => $msg->id])->handle($this->metaService);
                return response()->json([
                    'message' => 'Interactive list sent successfully',
                    'meta_message_id' => Message::find($msg->id)?->meta_message_id,
                ]);
            }

            SendOutboundMessage::dispatch($msg->id);

            return response()->json([
                'message' => 'Interactive list queued',
                'id' => $msg->id,
                'status' => 'queued',
            ], 202);
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

            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact->id,
                'meta_message_id' => null,
                'direction' => 'outbound',
                'type' => 'interactive',
                'content' => $data['body'],
                'interactive_payload' => $interactive,
                'status' => 'queued',
                'sent_at' => null,
            ]);

            if ($this->queueIsSync()) {
                app(SendOutboundMessage::class, ['messageId' => $msg->id])->handle($this->metaService);
                return response()->json([
                    'message' => 'Interactive buttons sent successfully',
                    'meta_message_id' => Message::find($msg->id)?->meta_message_id,
                ]);
            }

            SendOutboundMessage::dispatch($msg->id);

            return response()->json([
                'message' => 'Interactive buttons queued',
                'id' => $msg->id,
                'status' => 'queued',
            ], 202);
        }

        $data = $request->validate([
            'message' => 'required|string',
        ]);

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact->id,
            'meta_message_id' => null,
            'direction' => 'outbound',
            'type' => 'text',
            'content' => $data['message'],
            'status' => 'queued',
            'sent_at' => null,
        ]);

        if ($this->queueIsSync()) {
            app(SendOutboundMessage::class, ['messageId' => $msg->id])->handle($this->metaService);
            return response()->json([
                'message' => 'Message sent successfully',
                'meta_message_id' => Message::find($msg->id)?->meta_message_id,
            ]);
        }

        SendOutboundMessage::dispatch($msg->id);

        return response()->json([
            'message' => 'Message queued',
            'id' => $msg->id,
            'status' => 'queued',
        ], 202);
    }
}
