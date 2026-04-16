<?php

namespace App\Services;

use App\Models\ConversationState;
use App\Models\Flow;
use App\Models\AiSetting;
use App\Models\Rating;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Events\NewMessageReceived;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlowEngine
{
    public function __construct(
        private MetaWhatsAppService $metaService,
        private OrderTrackingService $orderTracking,
    ) {}

    public function processIncoming(string $phone, array $incoming): void
    {
        $flow = Flow::query()->first();
        if (! $flow) {
            Log::warning("FlowEngine: no flow found, skipping. phone={$phone}");
            return;
        }

        $state = ConversationState::query()->firstOrCreate(
            ['phone' => $phone],
            [
                'flow_id' => $flow->id,
                'mode' => 'auto',
                'language' => 'auto',
                'variables' => [],
                'message_history' => [],
                'session_started_at' => now(),
                'current_node_id' => $this->getStartNodeId($flow) ?? 'start',
            ],
        );

        // Mode revert handling.
        if ($state->mode === 'manual' && $state->mode_revert_at && $state->mode_revert_at->isPast()) {
            $state->mode = 'auto';
            $state->mode_revert_at = null;
            $state->save();
        }

        $text = trim((string) Arr::get($incoming, 'text', Arr::get($incoming, 'content', '')));

        $skipHistoryAppend = false;
        if ($state->mode === 'manual') {
            if ($text !== '' && $this->matchesManualModeResumeWords($flow, $text)) {
                $this->resetAutomationSession($state, $flow);
                $skipHistoryAppend = true;
            } else {
                return;
            }
        }

        if (! $skipHistoryAppend) {
            $history = $state->message_history ?? [];
            $history[] = ['role' => 'user', 'content' => $text, 'timestamp' => now()->toISOString()];
            $state->message_history = array_slice($history, -40);
            $state->save();
        }

        // Manual mode is only entered via the flow (e.g. customer-care switch_mode node), not by free-text keywords,
        // so words like "stop" can safely mean "resume automation" while in manual.

        // Rating capture.
        if ($this->tryCaptureRating($state, $text)) {
            return;
        }

        // Awaiting input (ask_input/system_function) or awaiting interactive selection.
        if ($this->resumeIfAwaiting($flow, $state, $incoming, $text)) {
            $this->run($flow, $state);
            return;
        }

        $this->run($flow, $state);
    }

    private function sendAndPersistText(string $phone, string $text): void
    {
        if (trim($text) === '') {
            return;
        }

        $sent = $this->metaService->sendMessage($phone, $text);
        $this->persistOutbound($phone, [
            'type' => 'text',
            'content' => $text,
            'meta_message_id' => $sent['meta_message_id'] ?? null,
        ]);
    }

    private function run(Flow $flow, ConversationState $state): void
    {
        $nodes = $flow->nodes_json ?? [];
        $edges = $flow->edges_json ?? [];

        $guard = 0;
        while ($guard++ < 25) {
            $node = $this->findNode($nodes, $state->current_node_id);
            if (! $node) {
                Log::warning("FlowEngine: current node missing. phone={$state->phone}");
                return;
            }

            $nodeType = $node['type'] ?? null;
            $nodeId = $node['id'] ?? null;
            $data = $node['data'] ?? [];

            switch ($nodeType) {
                case 'start':
                    $welcome = (string) ($data['welcomeText'] ?? '');
                    if ($welcome !== '') {
                        $rendered = $this->render($welcome, $state->variables ?? []);
                        $sent = $this->metaService->sendMessage($state->phone, $rendered);
                        $this->persistOutbound($state->phone, [
                            'type' => 'text',
                            'content' => $rendered,
                            'meta_message_id' => $sent['meta_message_id'] ?? null,
                        ]);
                    }
                    $next = $this->nextNodeId($edges, (string) $nodeId, 'begin');
                    if (! $next) {
                        return;
                    }
                    $state->current_node_id = $next;
                    $state->save();
                    continue 2;

                case 'send_message':
                    $text = (string) ($data['text'] ?? '');
                    if ($text !== '') {
                        $rendered = $this->render($text, $state->variables ?? []);
                        $sent = $this->metaService->sendMessage($state->phone, $rendered);
                        $this->persistOutbound($state->phone, [
                            'type' => 'text',
                            'content' => $rendered,
                            'meta_message_id' => $sent['meta_message_id'] ?? null,
                        ]);
                    }
                    $next = $this->nextNodeId($edges, (string) $nodeId, 'out');
                    if (! $next) {
                        return;
                    }
                    $state->current_node_id = $next;
                    $state->save();
                    continue 2;

                case 'interactive_menu': {
                    $mode = (string) ($data['mode'] ?? 'list');
                    $headerText = (string) ($data['headerText'] ?? '');
                    $bodyText = (string) ($data['bodyText'] ?? '');
                    $bodyText = $this->render($bodyText, $state->variables ?? []);

                    if ($mode === 'buttons') {
                        $buttons = (array) ($data['buttons'] ?? []);
                        $waButtons = [];
                        foreach (array_slice($buttons, 0, 3) as $idx => $b) {
                            $bid = (string) ($b['id'] ?? "btn{$idx}");
                            $title = (string) ($b['title'] ?? "Button {$idx}");
                            $waButtons[] = ['type' => 'reply', 'reply' => ['id' => $bid, 'title' => $title]];
                        }

                        $interactive = [
                            'type' => 'button',
                            'body' => ['text' => $bodyText ?: 'Choose an option'],
                            'action' => ['buttons' => $waButtons],
                        ];

                        $sent = $this->metaService->sendInteractive($state->phone, $interactive);
                        $this->persistOutbound($state->phone, [
                            'type' => 'text',
                            'content' => (string) ($interactive['body']['text'] ?? ''),
                            'interactive_payload' => $interactive,
                            'meta_message_id' => $sent['meta_message_id'] ?? null,
                        ]);

                        $state->awaiting_input = [
                            'kind' => 'interactive',
                            'nodeId' => (string) $nodeId,
                            'mode' => 'buttons',
                        ];
                        $state->save();
                        return;
                    }

                    $buttonLabel = (string) ($data['buttonLabel'] ?? 'View options');
                    $sections = (array) ($data['sections'] ?? []);
                    $waSections = [];
                    foreach ($sections as $s) {
                        $title = (string) ($s['title'] ?? 'Section');
                        $rows = [];
                        foreach (($s['rows'] ?? []) as $r) {
                            $rows[] = [
                                'id' => (string) ($r['id'] ?? ''),
                                'title' => (string) ($r['title'] ?? ''),
                                'description' => $r['description'] ?? null,
                            ];
                        }
                        $waSections[] = ['title' => $title, 'rows' => $rows];
                    }

                    $interactive = [
                        'type' => 'list',
                        'body' => ['text' => $bodyText ?: 'Choose an option'],
                        'action' => [
                            'button' => $buttonLabel,
                            'sections' => $waSections,
                        ],
                    ];

                    $sent = $this->metaService->sendInteractive($state->phone, $interactive);
                    $this->persistOutbound($state->phone, [
                        'type' => 'text',
                        'content' => (string) ($interactive['body']['text'] ?? ''),
                        'interactive_payload' => $interactive,
                        'meta_message_id' => $sent['meta_message_id'] ?? null,
                    ]);

                    $state->awaiting_input = [
                        'kind' => 'interactive',
                        'nodeId' => (string) $nodeId,
                        'mode' => 'list',
                    ];
                    $state->save();
                    return;
                }

                case 'end_flow':
                    $closing = (string) ($data['closingText'] ?? '');
                    if ($closing !== '') {
                        $rendered = $this->render($closing, $state->variables ?? []);
                        $sent = $this->metaService->sendMessage($state->phone, $rendered);
                        $this->persistOutbound($state->phone, [
                            'type' => 'text',
                            'content' => $rendered,
                            'meta_message_id' => $sent['meta_message_id'] ?? null,
                        ]);
                    }
                    $state->delete();
                    return;

                case 'condition': {
                    $var = (string) ($data['variable'] ?? '');
                    $op = (string) ($data['operator'] ?? '==');
                    $value = (string) ($data['value'] ?? '');
                    $lhs = $var === '__language'
                        ? (string) ($state->language ?? '')
                        : Arr::get($state->variables ?? [], $var);
                    $rhs = $this->render($value, $state->variables ?? []);
                    $result = $this->evalCondition($lhs, $op, $rhs);
                    $handle = $result ? 'true' : 'false';
                    $next = $this->nextNodeId($edges, (string) $nodeId, $handle);
                    if (! $next) return;
                    $state->current_node_id = $next;
                    $state->save();
                    continue 2;
                }

                case 'ask_input': {
                    $question = $this->render((string) ($data['questionText'] ?? ''), $state->variables ?? []);
                    $varName = (string) ($data['variableName'] ?? '');
                    $validateType = (string) ($data['validateType'] ?? 'any');
                    $errorMessage = (string) ($data['errorMessage'] ?? 'Invalid value, please try again.');
                    if ($question !== '') {
                        $this->sendAndPersistText($state->phone, $question);
                    }
                    $state->awaiting_input = [
                        'kind' => 'ask_input',
                        'nodeId' => (string) $nodeId,
                        'variableName' => $varName,
                        'validateType' => $validateType,
                        'errorMessage' => $errorMessage,
                        'retries' => 0,
                    ];
                    $state->save();
                    return;
                }

                case 'api_call': {
                    $method = strtoupper((string) ($data['method'] ?? 'GET'));
                    $url = $this->render((string) ($data['url'] ?? ''), $state->variables ?? []);
                    if ($url === '') return;

                    $headers = (array) ($data['headers'] ?? []);
                    $varsCtx = $state->variables ?? [];
                    $headerArr = [];
                    foreach ($headers as $h) {
                        if (! isset($h['key'])) continue;
                        $headerArr[(string) $h['key']] = $this->render((string) ($h['value'] ?? ''), $varsCtx);
                    }

                    $bodyType = (string) ($data['bodyType'] ?? 'none');
                    $body = $data['body'] ?? null;
                    $bodyPayload = $body;
                    if ($bodyType === 'json' && is_array($body)) {
                        $bodyPayload = $this->renderDeep($body, $varsCtx);
                    } elseif ($bodyType === 'json' && is_string($body)) {
                        $rendered = $this->render($body, $varsCtx);
                        $decoded = json_decode($rendered, true);
                        $bodyPayload = is_array($decoded) ? $decoded : [];
                    }

                    $req = Http::timeout(20)->acceptJson()->withHeaders($headerArr);
                    $resp = match ($method) {
                        'POST' => $req->post($url, $bodyType === 'json' ? (array) $bodyPayload : []),
                        'PUT' => $req->put($url, $bodyType === 'json' ? (array) $bodyPayload : []),
                        'PATCH' => $req->patch($url, $bodyType === 'json' ? (array) $bodyPayload : []),
                        'DELETE' => $req->delete($url),
                        default => $req->get($url),
                    };

                    $status = $resp->status();
                    $json = $resp->json();

                    $saveVar = (string) ($data['saveResponseVar'] ?? '');
                    if ($saveVar !== '') {
                        $vars = $state->variables ?? [];
                        $vars[$saveVar] = $json;
                        $state->variables = $vars;
                    }

                    $extracts = (array) ($data['responseExtracts'] ?? []);
                    foreach ($extracts as $ex) {
                        $path = (string) ($ex['jsonPath'] ?? '');
                        $varName = (string) ($ex['variableName'] ?? '');
                        if ($path === '' || $varName === '') {
                            continue;
                        }
                        $val = Arr::get($json ?? [], $path);
                        $vars = $state->variables ?? [];
                        $vars[$varName] = $val;
                        $state->variables = $vars;
                    }

                    $mappings = (array) ($data['mappings'] ?? []);
                    $matchedHandle = null;
                    foreach ($mappings as $m) {
                        $mid = (string) ($m['id'] ?? '');
                        if ($mid === '') continue;
                        $label = (string) ($m['label'] ?? '');
                        $condType = (string) ($m['conditionType'] ?? 'status_equals');
                        $expected = (string) ($m['expected'] ?? '');
                        $field = (string) ($m['field'] ?? '');

                        $ok = false;
                        if ($condType === 'status_equals') {
                            $ok = ((string) $status) === $expected;
                        } elseif ($condType === 'body_contains') {
                            $ok = is_string($resp->body()) && str_contains($resp->body(), $expected);
                        } elseif ($condType === 'body_field_equals') {
                            $val = Arr::get($json ?? [], $field);
                            $ok = (string) $val === $expected;
                        }

                        if ($ok) {
                            $matchedHandle = "map:{$mid}";
                            break;
                        }
                    }

                    $handle = $matchedHandle ?: ($resp->successful() ? 'success' : 'error');
                    $next = $this->nextNodeId($edges, (string) $nodeId, $handle);
                    if (! $next) return;
                    $state->current_node_id = $next;
                    $state->save();
                    continue 2;
                }

                case 'ai_reply': {
                    $ai = AiSetting::query()->first();
                    if (! $ai || ! $ai->api_key) {
                        $this->sendAndPersistText($state->phone, 'AI is not configured yet.');
                        $next = $this->nextNodeId($edges, (string) $nodeId, 'replied');
                        if (! $next) return;
                        $state->current_node_id = $next;
                        $state->save();
                        continue 2;
                    }

                    $tone = (string) ($data['tone'] ?? $ai->default_tone ?? 'helpful');
                    $lang = (string) ($data['language'] ?? $ai->default_language ?? 'auto');
                    $sys = trim((string) ($ai->system_prompt ?? ''));
                    $extraSys = trim((string) ($data['systemPrompt'] ?? ''));
                    $includeHistory = (bool) ($data['includeHistory'] ?? true);
                    $context = trim((string) ($data['context'] ?? ''));

                    $system = trim(implode("\n\n", array_filter([$sys, $extraSys, $context])));
                    $messages = [];
                    if ($system !== '') {
                        $messages[] = ['role' => 'system', 'content' => $system];
                    }
                    if ($includeHistory) {
                        foreach (($state->message_history ?? []) as $h) {
                            $role = $h['role'] ?? 'user';
                            $content = $h['content'] ?? '';
                            if ($content === '') continue;
                            $messages[] = ['role' => $role, 'content' => $content];
                        }
                    }

                    $messages[] = [
                        'role' => 'system',
                        'content' => "Tone: {$tone}\nLanguage: {$lang}",
                    ];

                    $reply = $this->callAi($ai, $messages);
                    if ($reply === null) {
                        $this->sendAndPersistText($state->phone, 'AI failed to respond.');
                    } else {
                        $saveVar = (string) ($data['saveAsVar'] ?? '');
                        if ($saveVar !== '') {
                            $vars = $state->variables ?? [];
                            $vars[$saveVar] = $reply;
                            $state->variables = $vars;
                            $state->save();
                        }
                        $this->sendAndPersistText($state->phone, $reply);
                    }

                    $next = $this->nextNodeId($edges, (string) $nodeId, 'replied');
                    if (! $next) return;
                    $state->current_node_id = $next;
                    $state->save();
                    continue 2;
                }

                case 'switch_language': {
                    $state->language = (string) ($data['language'] ?? 'auto');
                    $state->save();
                    $next = $this->nextNodeId($edges, (string) $nodeId, 'out');
                    if (! $next) return;
                    $state->current_node_id = $next;
                    $state->save();
                    continue 2;
                }

                case 'switch_mode': {
                    $newMode = (string) ($data['mode'] ?? 'manual');
                    $state->mode = $newMode === 'auto' ? 'auto' : 'manual';
                    $minutes = (int) ($data['autoRevertMinutes'] ?? 0);
                    $state->mode_revert_at = ($state->mode === 'manual' && $minutes > 0) ? now()->addMinutes($minutes) : null;
                    $state->save();
                    $next = $this->nextNodeId($edges, (string) $nodeId, 'out');
                    if (! $next) return;
                    $state->current_node_id = $next;
                    $state->save();
                    continue 2;
                }

                case 'system_function': {
                    $fn = (string) ($data['functionName'] ?? 'track_order');
                    $params = (array) ($data['parameters'] ?? []);
                    $saveVar = (string) ($data['saveResultVar'] ?? '');

                    foreach ($params as $p) {
                        $name = (string) ($p['name'] ?? '');
                        if ($name === '') {
                            continue;
                        }
                        $useVar = (string) ($p['useVariable'] ?? '');
                        $question = (string) ($p['question'] ?? '');

                        $has = $useVar !== '' && Arr::has($state->variables ?? [], $useVar);
                        if (! $has) {
                            $ask = $question !== '' ? $question : "Please provide {$name}";
                            $validateType = (string) ($p['validateType'] ?? 'any');
                            $errMsg = trim((string) ($p['errorMessage'] ?? ''));
                            if ($errMsg === '') {
                                $errMsg = 'Invalid value, please try again.';
                            }
                            $this->sendAndPersistText($state->phone, $ask);
                            $state->awaiting_input = [
                                'kind' => 'system_param',
                                'nodeId' => (string) $nodeId,
                                'paramName' => $name,
                                'variableName' => $useVar !== '' ? $useVar : $name,
                                'validateType' => $validateType,
                                'errorMessage' => $errMsg,
                                'paramQuestion' => $ask,
                                'retries' => 0,
                            ];
                            $state->save();
                            return;
                        }
                    }

                    $result = $this->executeSystemFunction($fn, $state->variables ?? [], (string) $state->phone);
                    $vars = $state->variables ?? [];
                    foreach ([
                        'res_ar',
                        'res_en',
                        'account_id',
                        'order_number',
                        'store_id',
                        'tracking',
                        'tracking_url',
                        'tracking_status',
                        'store',
                        'order_status',
                        'carrier',
                        'tracking_number',
                        'shipping_number',
                        'result',
                    ] as $k) {
                        if (array_key_exists($k, $result)) {
                            $vars[$k] = $result[$k];
                        }
                    }
                    if ($saveVar !== '') {
                        $vars[$saveVar] = $result;
                    }
                    $state->variables = $vars;
                    $state->save();

                    $handle = ($result['ok'] ?? false) ? 'success' : 'error';
                    $next = $this->nextNodeId($edges, (string) $nodeId, $handle);
                    if (! $next) {
                        return;
                    }
                    $state->current_node_id = $next;
                    $state->save();
                    continue 2;
                }

                case 'loop_goto': {
                    $target = (string) ($data['targetNodeId'] ?? '');
                    if ($target === '') {
                        return;
                    }
                    $state->current_node_id = $target;
                    $state->save();
                    // Continue the run loop so the target node executes in the same turn (e.g. show language picker).
                    continue 2;
                }

                case 'rate_service_template': {
                    $templateText = $this->render((string) ($data['templateText'] ?? ''), $state->variables ?? []);
                    $windowHours = (int) ($data['replyWindowHours'] ?? 24);
                    $phoneVar = (string) ($data['phoneVariable'] ?? 'phone');
                    $orderVar = (string) ($data['orderNumberVariable'] ?? 'order_number');
                    $orderNo = (string) Arr::get($state->variables ?? [], $orderVar, '');
                    $phoneForRating = (string) Arr::get($state->variables ?? [], $phoneVar, $state->phone);

                    // Send as plain text for now. (Template send can be added if you store template_name in node data.)
                    if ($templateText !== '') {
                        $this->sendAndPersistText($state->phone, $templateText);
                    }

                    $state->rating_pending = [
                        'phone' => $phoneForRating,
                        'orderNumber' => $orderNo,
                        'sentAt' => now()->toISOString(),
                        'windowHours' => $windowHours,
                    ];
                    $state->save();

                    $next = $this->nextNodeId($edges, (string) $nodeId, 'sent');
                    if (! $next) return;
                    $state->current_node_id = $next;
                    $state->save();
                    continue 2;
                }

                default:
                    return;
            }
        }
    }

    /**
     * When in manual / human-handoff mode, substring-match against these phrases always resumes automation
     * (so "close", "cancel", etc. work even if the flow JSON omits triggerWords).
     *
     * @var list<string>
     */
    private const DEFAULT_MANUAL_RESUME_SUBSTRINGS = [
        'close', 'closed', 'closing', 'cancel', 'cancelled', 'cancellation',
        'stop', 'stopped', 'quit', 'exit', 'done', 'dismiss', 'resolved', 'goodbye', 'bye',
        'إغلاق', 'اغلاق', 'إلغاء', 'الغاء', 'انهاء', 'إنهاء', 'أوقف', 'وقف',
    ];

    /**
     * Manual resume: built-in cancel/close phrases plus comma-separated triggerWords on switch_mode(manual) nodes.
     */
    private function matchesManualModeResumeWords(Flow $flow, string $text): bool
    {
        $t = mb_strtolower($text);
        foreach (self::DEFAULT_MANUAL_RESUME_SUBSTRINGS as $w) {
            if (str_contains($t, mb_strtolower($w))) {
                return true;
            }
        }

        $nodes = $flow->nodes_json ?? [];
        foreach ($nodes as $n) {
            if (($n['type'] ?? null) !== 'switch_mode') {
                continue;
            }
            $data = $n['data'] ?? [];
            if (($data['mode'] ?? null) !== 'manual') {
                continue;
            }
            $raw = (string) ($data['triggerWords'] ?? '');
            foreach (explode(',', $raw) as $w) {
                $w = trim(mb_strtolower($w));
                if ($w !== '' && str_contains($t, $w)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resetAutomationSession(ConversationState $state, Flow $flow): void
    {
        $state->mode = 'auto';
        $state->mode_revert_at = null;
        $state->current_node_id = $this->getStartNodeId($flow) ?? 'start';
        $state->awaiting_input = null;
        $state->rating_pending = null;
        $state->variables = [];
        $state->message_history = [];
        $state->session_started_at = now();
        $state->save();
    }

    private function resumeIfAwaiting(Flow $flow, ConversationState $state, array $incoming, string $text): bool
    {
        $await = $state->awaiting_input;
        if (! is_array($await) || empty($await['kind'])) {
            return false;
        }

        $kind = (string) ($await['kind'] ?? '');
        $nodeId = (string) ($await['nodeId'] ?? '');

        $edges = $flow->edges_json ?? [];

        if ($kind === 'interactive') {
            $interactive = Arr::get($incoming, 'interactive');
            $mode = (string) ($await['mode'] ?? 'list');
            $selectedId = null;
            if (is_array($interactive)) {
                if (($interactive['type'] ?? null) === 'button_reply') {
                    $selectedId = $interactive['button_reply']['id'] ?? null;
                } elseif (($interactive['type'] ?? null) === 'list_reply') {
                    $selectedId = $interactive['list_reply']['id'] ?? null;
                }
            }
            $handle = null;
            if ($selectedId) {
                $handle = $mode === 'buttons' ? "btn:{$selectedId}" : "row:{$selectedId}";
            }
            $next = $handle ? $this->nextNodeId($edges, $nodeId, $handle) : null;
            if (! $next) {
                $next = $this->nextNodeId($edges, $nodeId, 'fallback');
            }

            if ($next) {
                $node = $this->findNode($flow->nodes_json ?? [], $nodeId);
                $data = $node['data'] ?? [];
                $saveAs = (string) ($data['saveSelectionAs'] ?? '');
                if ($saveAs !== '' && $selectedId) {
                    $vars = $state->variables ?? [];
                    $vars[$saveAs] = (string) $selectedId;
                    $state->variables = $vars;
                }
                $state->awaiting_input = null;
                $state->current_node_id = $next;
                $state->save();
                return true;
            }

            return false;
        }

        if ($kind === 'ask_input' || $kind === 'system_param') {
            $varName = (string) ($await['variableName'] ?? '');
            $validateType = (string) ($await['validateType'] ?? 'any');
            $errorMessage = (string) ($await['errorMessage'] ?? 'Invalid value, please try again.');
            $retries = (int) ($await['retries'] ?? 0);

            if (! $this->validate($validateType, $text)) {
                $retries++;
                $state->awaiting_input = array_merge($await, ['retries' => $retries]);
                $state->save();
                $this->sendAndPersistText($state->phone, $errorMessage);
                if ($kind === 'ask_input') {
                    $node = $this->findNode($flow->nodes_json ?? [], $nodeId);
                    $q = (string) (($node['data']['questionText'] ?? '') ?: '');
                    if ($q !== '') {
                        $this->sendAndPersistText($state->phone, $q);
                    }
                } elseif ($kind === 'system_param') {
                    $q = (string) ($await['paramQuestion'] ?? '');
                    if ($q !== '') {
                        $this->sendAndPersistText($state->phone, $q);
                    }
                }
                return false;
            }

            if ($varName !== '') {
                $vars = $state->variables ?? [];
                $vars[$varName] = $text;
                $state->variables = $vars;
            }

            $state->awaiting_input = null;

            if ($kind === 'ask_input') {
                $next = $this->nextNodeId($edges, $nodeId, 'answer');
                if ($next) $state->current_node_id = $next;
            }

            // system_param resumes at same node (system_function) so run() will retry param resolution.
            $state->save();
            return true;
        }

        return false;
    }

    private function tryCaptureRating(ConversationState $state, string $text): bool
    {
        $pending = $state->rating_pending;
        if (! is_array($pending)) return false;
        $sentAt = $pending['sentAt'] ?? null;
        $hours = (int) ($pending['windowHours'] ?? 24);
        if (! $sentAt) return false;

        $sent = now()->createFromFormat(\DateTimeInterface::ATOM, $sentAt) ?: now();
        if ($sent->copy()->addHours($hours)->isPast()) {
            // Window expired: clear pending and continue normal flow.
            $state->rating_pending = null;
            $state->save();
            return false;
        }

        if (! preg_match('/^[1-5]$/', trim($text))) {
            return false;
        }

        Rating::create([
            'phone' => (string) ($pending['phone'] ?? $state->phone),
            'order_number' => (string) ($pending['orderNumber'] ?? null),
            'rating' => (int) $text,
            'captured_at' => now(),
        ]);

        $state->rating_pending = null;
        $state->save();
        return true;
    }

    private function validate(string $type, string $text): bool
    {
        $t = trim($text);
        if ($type === 'any') return $t !== '';
        if ($type === 'digits') return $t !== '' && ctype_digit($t);
        if ($type === 'numeric') return is_numeric($t);
        if ($type === 'email') return filter_var($t, FILTER_VALIDATE_EMAIL) !== false;
        if ($type === 'phone') return preg_match('/^\\+?[0-9]{7,15}$/', preg_replace('/\\s+/', '', $t)) === 1;
        if ($type === 'yes-no') return in_array(mb_strtolower($t), ['yes', 'no', 'y', 'n', 'نعم', 'لا'], true);
        if ($type === 'text') return $t !== '';
        return true;
    }

    private function evalCondition($lhs, string $op, string $rhs): bool
    {
        $l = is_scalar($lhs) ? (string) $lhs : '';
        $r = (string) $rhs;
        return match ($op) {
            '==' => $l === $r,
            '!=' => $l !== $r,
            '>' => (float) $l > (float) $r,
            '<' => (float) $l < (float) $r,
            '>=' => (float) $l >= (float) $r,
            '<=' => (float) $l <= (float) $r,
            'contains' => str_contains($l, $r),
            'not_contains' => ! str_contains($l, $r),
            'starts_with' => str_starts_with($l, $r),
            'ends_with' => str_ends_with($l, $r),
            'is_numeric' => is_numeric($l),
            'is_empty' => trim($l) === '',
            'is_true' => in_array(mb_strtolower($l), ['true', '1', 'yes', 'y'], true),
            'is_false' => in_array(mb_strtolower($l), ['false', '0', 'no', 'n'], true),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function executeSystemFunction(string $fn, array $vars, string $waPhone): array
    {
        $order = (string) Arr::get($vars, 'order_number', '');
        $storeId = (string) Arr::get($vars, 'store_id', '');

        return match ($fn) {
            'track_order', 'trackOrder' => $this->orderTracking->trackOrder($order, $waPhone),
            'check_order', 'checkOrder' => $this->orderTracking->checkOrder($order, $waPhone),
            'order_missed', 'orderMissed', 'order_issue', 'orderIssue' => $this->orderTracking->orderMissed($order, $storeId, $waPhone),
            'get_order_details' => ['ok' => true, 'res_ar' => '', 'res_en' => '', 'order' => $order],
            'check_stock' => ['ok' => true, 'res_ar' => '', 'res_en' => '', 'in_stock' => true],
            'get_customer_info' => ['ok' => true, 'res_ar' => '', 'res_en' => '', 'phone' => Arr::get($vars, 'phone', null)],
            default => [
                'ok' => false,
                'res_ar' => 'هذه الوظيفة غير مفعّلة بعد.',
                'res_en' => 'This function is not available yet.',
                'error' => 'Unknown function',
            ],
        };
    }

    private function callAi(AiSetting $ai, array $messages): ?string
    {
        $provider = $ai->provider;

        // Minimal implementation: OpenAI-compatible Chat Completions.
        if (in_array($provider, ['openai', 'custom', 'groq'], true)) {
            $base = $ai->base_url ?: 'https://api.openai.com';
            $url = rtrim($base, '/').'/v1/chat/completions';

            try {
                $res = Http::timeout(40)
                    ->acceptJson()
                    ->withToken($ai->api_key)
                    ->post($url, [
                        'model' => $ai->model,
                        'messages' => $messages,
                        'temperature' => 0.7,
                    ]);

                if (! $res->successful()) {
                    Log::warning('AI request failed: '.$res->body());
                    return null;
                }

                $json = $res->json();
                return $json['choices'][0]['message']['content'] ?? null;
            } catch (\Exception $e) {
                Log::warning('AI request exception: '.$e->getMessage());
                return null;
            }
        }

        // TODO: add Anthropic/Gemini in future.
        return null;
    }

    /**
     * Replace {{var}} in nested arrays/strings for JSON request bodies.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function renderDeep(array $data, array $vars): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $out[$k] = $this->render($v, $vars);
            } elseif (is_array($v)) {
                $out[$k] = $this->renderDeep($v, $vars);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private function render(string $template, array $vars): string
    {
        return preg_replace_callback('/\\{\\{\\s*([a-zA-Z0-9_\\.]+)\\s*\\}\\}/', function ($m) use ($vars) {
            $key = $m[1] ?? '';
            $value = Arr::get($vars, $key);
            return is_scalar($value) ? (string) $value : '';
        }, $template) ?? $template;
    }

    private function getStartNodeId(Flow $flow): ?string
    {
        foreach (($flow->nodes_json ?? []) as $n) {
            if (($n['type'] ?? null) === 'start') {
                return (string) ($n['id'] ?? 'start');
            }
        }

        return null;
    }

    private function findNode(array $nodes, ?string $id): ?array
    {
        if (! $id) return null;
        foreach ($nodes as $n) {
            if (($n['id'] ?? null) === $id) return $n;
        }
        return null;
    }

    private function nextNodeId(array $edges, string $sourceNodeId, ?string $sourceHandle): ?string
    {
        foreach ($edges as $e) {
            if (($e['source'] ?? null) !== $sourceNodeId) continue;
            if (($e['sourceHandle'] ?? null) !== $sourceHandle) continue;
            return $e['target'] ?? null;
        }
        return null;
    }

    /**
     * Persist outbound (bot/flow) messages so the chat UI shows full history.
     *
     * @param  array{type?: string, content?: string|null, interactive_payload?: array|null, meta_message_id?: string|null}  $attrs
     */
    private function persistOutbound(string $phone, array $attrs): void
    {
        $contact = Contact::query()->firstOrCreate(['phone_number' => $phone], ['opt_in' => true]);

        $conversation = Conversation::query()->firstOrCreate(
            ['contact_id' => $contact->id],
            ['status' => 'open', 'window_expires_at' => null]
        );

        $now = now();
        $conversation->update([
            'last_message_at' => $now,
        ]);

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'meta_message_id' => $attrs['meta_message_id'] ?? null,
            'direction' => 'outbound',
            'type' => $attrs['type'] ?? 'text',
            'content' => $attrs['content'] ?? null,
            'interactive_payload' => $attrs['interactive_payload'] ?? null,
            'status' => 'sent',
            'sent_at' => $now,
        ]);
        event(new NewMessageReceived($msg));
    }
}

