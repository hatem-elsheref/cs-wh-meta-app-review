<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(private MetaWhatsAppService $metaService) {}

    public function index(Request $request)
    {
        $conversations = Conversation::with('contact')
            ->orderBy('last_message_at', 'desc')
            ->paginate(20);

        return response()->json([
            'data' => $conversations->items(),
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
            'window_expires_at' => now()->addHours(24),
        ]);

        return response()->json([
            'message' => 'Conversation created',
            'data' => $conversation,
        ], 201);
    }

    public function show(int $id)
    {
        $conversation = Conversation::with(['contact', 'messages' => function ($q) {
            $q->orderBy('created_at', 'desc')->limit(50);
        }])->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'contact' => $conversation->contact,
                'window_open' => $conversation->isWindowOpen(),
                'window_expires_at' => $conversation->window_expires_at,
                'messages' => $conversation->messages,
            ],
        ]);
    }

    public function messages(Request $request, int $id)
    {
        $conversation = Conversation::findOrFail($id);

        $messages = Message::where('conversation_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json([
            'data' => $messages->items(),
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

        if ($conversation->mustUseTemplate()) {
            $data = $request->validate([
                'template_name' => 'required|string',
                'template_components' => 'nullable|array',
            ]);

            $result = $this->metaService->sendMessage(
                $conversation->contact->phone_number,
                '',
                $data['template_name'],
                $data['template_components'] ?? null
            );

            if ($result['success']) {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'contact_id' => $conversation->contact->id,
                    'meta_message_id' => $result['meta_message_id'],
                    'direction' => 'outbound',
                    'type' => 'template',
                    'template_name' => $data['template_name'],
                    'template_components' => $data['template_components'] ?? null,
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                $conversation->refreshWindow();

                return response()->json([
                    'message' => 'Template sent successfully',
                    'meta_message_id' => $result['meta_message_id'],
                ]);
            }

            return response()->json(['error' => $result['error']], 422);
        }

        $data = $request->validate([
            'message' => 'required|string',
        ]);

        $result = $this->metaService->sendMessage(
            $conversation->contact->phone_number,
            $data['message']
        );

        if ($result['success']) {
            Message::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact->id,
                'meta_message_id' => $result['meta_message_id'],
                'direction' => 'outbound',
                'type' => 'text',
                'content' => $data['message'],
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            $conversation->refreshWindow();

            return response()->json([
                'message' => 'Message sent successfully',
                'meta_message_id' => $result['meta_message_id'],
            ]);
        }

        return response()->json(['error' => $result['error']], 422);
    }
}
