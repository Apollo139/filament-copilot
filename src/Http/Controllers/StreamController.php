<?php

declare(strict_types=1);

namespace EslamRedaDiv\FilamentCopilot\Http\Controllers;

use EslamRedaDiv\FilamentCopilot\Agent\CopilotAgent;
use EslamRedaDiv\FilamentCopilot\Enums\ToolCallStatus;
use EslamRedaDiv\FilamentCopilot\Events\CopilotMessageSent;
use EslamRedaDiv\FilamentCopilot\Events\CopilotResponseReceived;
use EslamRedaDiv\FilamentCopilot\Events\CopilotToolApprovalRequired;
use EslamRedaDiv\FilamentCopilot\Events\CopilotToolExecuted;
use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use EslamRedaDiv\FilamentCopilot\Models\CopilotConversation;
use EslamRedaDiv\FilamentCopilot\Models\CopilotToolCall;
use EslamRedaDiv\FilamentCopilot\Services\ConversationManager;
use EslamRedaDiv\FilamentCopilot\Services\RateLimitService;
use EslamRedaDiv\FilamentCopilot\Services\ToolRegistry;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamController
{
    public function stream(Request $request): StreamedResponse
    {
        $request->validate([
            'message' => ['nullable', 'string', 'max:10000', 'required_without:decisions', 'prohibits:decisions'],
            'decisions' => ['nullable', 'array', 'required_without:message', 'prohibits:message'],
            'decisions.*.action' => ['required_with:decisions', 'in:approve,reject'],
            'decisions.*.result' => ['nullable', 'string'],
            'conversation_id' => ['nullable', 'string'],
            'panel_id' => ['required', 'string'],
        ]);

        $panelId = $request->input('panel_id');

        // Set up Filament panel context so the correct auth guard is used
        try {
            Filament::setCurrentPanel($panelId);
        } catch (\Throwable) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $user = Filament::auth()->user();

        if (! $user) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        /** @var FilamentCopilotPlugin $plugin */
        $plugin = FilamentCopilotPlugin::get();

        if (! $plugin->isAuthorized($user)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $tenant = Filament::getTenant();
        $content = $request->input('message');
        $decisions = $request->input('decisions');
        $conversationId = $request->input('conversation_id');

        /** @var RateLimitService $rateLimitService */
        $rateLimitService = app(RateLimitService::class);

        if (! $decisions && config('filament-copilot.rate_limits.enabled') && ! $rateLimitService->canSendMessage($user, $panelId, $tenant)) {
            return $this->sseResponse(function () {
                $this->sendSseEvent('error', ['message' => __('filament-copilot::filament-copilot.rate_limit_exceeded')]);
                $this->sendSseEvent('done', []);
            });
        }

        /** @var ConversationManager $conversationManager */
        $conversationManager = app(ConversationManager::class);

        if ($conversationId) {
            $conversation = CopilotConversation::query()
                ->forPanel($panelId)
                ->forParticipant($user)
                ->forTenant($tenant)
                ->find($conversationId);

            if (! $conversation) {
                return $this->sseResponse(function () {
                    $this->sendSseEvent('error', ['message' => 'Conversation not found.']);
                    $this->sendSseEvent('done', []);
                });
            }
        } else {
            $conversation = $conversationManager->create($user, $panelId, $tenant);
        }

        $userMessage = null;

        if ($content !== null) {
            $userMessage = $conversationManager->addUserMessage($conversation, $content);
            event(new CopilotMessageSent($conversation, $content, $panelId));
        } elseif (! ($conversation->metadata['ai_conversation_id'] ?? null)) {
            return $this->sseResponse(function () {
                $this->sendSseEvent('error', ['message' => 'This conversation cannot be resumed.']);
                $this->sendSseEvent('done', []);
            });
        }

        return $this->sseResponse(function () use ($conversation, $conversationManager, $user, $panelId, $tenant, $rateLimitService, $plugin, $userMessage, $content, $decisions) {
            $this->sendSseEvent('conversation', ['id' => $conversation->id]);

            try {
                /** @var ToolRegistry $toolRegistry */
                $toolRegistry = app(ToolRegistry::class);

                /** @var CopilotAgent $agent */
                $agent = app(CopilotAgent::class);

                $sdkConversationId = $conversation->metadata['ai_conversation_id'] ?? null;

                $agent->forPanel($panelId)
                    ->forTenant($tenant)
                    ->withTools($toolRegistry->buildTools($panelId, $user, $tenant, $conversation->id))
                    ->withSystemPrompt($plugin->getSystemPrompt());

                if ($sdkConversationId) {
                    $agent->continue($sdkConversationId, as: $user);
                } else {
                    $agent->forUser($user);
                }

                $provider = $plugin->getProvider();
                $model = $plugin->getModel();

                // Send start event
                $this->sendSseEvent('start', []);

                $prompt = $decisions
                    ? \Laravel\Ai\Approvals\Decisions::from(collect($decisions)->map(
                        fn (array $decision) => ($decision['action'] ?? null) === 'approve'
                            ? \Laravel\Ai\Approvals\Decision::approve()
                            : \Laravel\Ai\Approvals\Decision::reject($decision['result'] ?? null)
                    )->all())
                    : $content;

                $streamResponse = $agent->stream(
                    prompt: $prompt,
                    provider: $provider,
                    model: $model,
                );
                $sdkConversationId = null;
                $streamResponse->then(function ($response) use (&$sdkConversationId): void {
                    $sdkConversationId = $response->conversationId;
                });

                $responseText = '';
                // Set once a tool call or result ends a step, so the next
                // step's text starts a new paragraph instead of gluing onto
                // the previous step's last sentence (and breaking Markdown).
                // Whitespace-only deltas pass through and leave it set; the
                // separator goes before the next step's first real text.
                $stepEnded = false;
                $usage = null;
                $shouldLogToolCalls = config('filament-copilot.audit.enabled', true)
                    && config('filament-copilot.audit.log_tool_calls', true);

                /** @var array<string, CopilotToolCall> $toolCallsByProviderId */
                $toolCallsByProviderId = [];
                $pendingApprovals = [];
                $toolCallMessage = $userMessage ?: $conversation->messages()
                    ->where('role', \EslamRedaDiv\FilamentCopilot\Enums\MessageRole::User)
                    ->latest()
                    ->first();

                if ($decisions && $toolCallMessage) {
                    foreach ($decisions as $providerId => $decision) {
                        $toolCall = $toolCallMessage->toolCalls()->where('provider_id', $providerId)->first();

                        if ($toolCall) {
                            ($decision['action'] ?? null) === 'approve'
                                ? $toolCall->approve()
                                : $toolCall->reject();
                            $toolCallsByProviderId[$providerId] = $toolCall;
                        }
                    }
                }

                // Stream real-time chunks from the AI provider
                foreach ($streamResponse as $event) {
                    if ($event instanceof \Laravel\Ai\Streaming\Events\TextDelta) {
                        $text = $event->delta;

                        if ($stepEnded && trim($text) !== '') {
                            $text = $this->stepSeparator($responseText, $text).$text;
                            $stepEnded = false;
                        }

                        $responseText .= $text;
                        $this->sendSseEvent('chunk', ['text' => $text]);
                    } elseif ($event instanceof \Laravel\Ai\Streaming\Events\ToolCall) {
                        $stepEnded = true;

                        $this->sendSseEvent('tool_call', [
                            'tool_id' => $event->toolCall->id,
                            'tool_name' => $event->toolCall->name,
                            'arguments' => $event->toolCall->arguments,
                        ]);

                        if ($shouldLogToolCalls) {
                            $toolCallsByProviderId[$event->toolCall->id] = $toolCallMessage?->toolCalls()->create([
                                'provider_id' => $event->toolCall->id,
                                'tool_name' => $event->toolCall->name,
                                'tool_input' => $event->toolCall->arguments,
                                'status' => ToolCallStatus::Pending,
                            ]);
                        }
                    } elseif ($event instanceof \Laravel\Ai\Streaming\Events\ToolResult) {
                        $stepEnded = true;

                        $rawResult = is_string($event->toolResult->result) ? $event->toolResult->result : json_encode($event->toolResult->result);

                        $this->sendSseEvent('tool_result', [
                            'tool_id' => $event->toolResult->id ?? '',
                            'tool_name' => $event->toolResult->name ?? '',
                            'result' => $rawResult,
                            'success' => $event->successful,
                            'error' => $event->error,
                        ]);

                        if ($shouldLogToolCalls) {
                            $providerToolCallId = $event->toolResult->id;

                            $toolCall = $toolCallsByProviderId[$providerToolCallId] ?? null;

                            if (! $toolCall) {
                                $toolCall = $toolCallMessage?->toolCalls()->create([
                                    'provider_id' => $providerToolCallId,
                                    'tool_name' => $event->toolResult->name,
                                    'tool_input' => $event->toolResult->arguments,
                                    'status' => ToolCallStatus::Pending,
                                ]);

                                $toolCallsByProviderId[$providerToolCallId] = $toolCall;
                            }

                            $toolCall->update([
                                'status' => $event->successful ? ToolCallStatus::Executed : ToolCallStatus::Failed,
                                'tool_output' => $rawResult,
                            ]);

                            event(new CopilotToolExecuted(
                                toolCall: $toolCall->fresh(),
                                toolName: $toolCall->tool_name,
                                result: $rawResult,
                            ));
                        }
                    } elseif (get_class($event) === 'Laravel\\Ai\\Streaming\\Events\\ToolApprovalRequest') {
                        foreach ($event->pendingApprovals as $approval) {
                            $pendingApprovals[] = $approval->toArray();

                            if ($shouldLogToolCalls && $userMessage) {
                                $toolCall = $toolCallsByProviderId[$approval->id] ?? $userMessage->toolCalls()
                                    ->where('tool_name', $approval->tool)
                                    ->where('status', ToolCallStatus::Pending)
                                    ->latest()
                                    ->first();

                                if (! $toolCall) {
                                    $toolCall = $toolCallMessage?->toolCalls()->create([
                                        'provider_id' => $approval->id,
                                        'tool_name' => $approval->tool,
                                        'tool_input' => $approval->arguments,
                                        'status' => ToolCallStatus::Pending,
                                        'requires_approval' => true,
                                    ]);
                                } else {
                                    $toolCall->update([
                                        'tool_input' => $approval->arguments,
                                        'requires_approval' => true,
                                    ]);
                                }

                                $toolCallsByProviderId[$approval->id] = $toolCall;
                                event(new CopilotToolApprovalRequired($toolCall->fresh()));
                            }
                        }

                        $this->sendSseEvent('tool_approval_request', ['approvals' => $pendingApprovals]);
                    } elseif ($event instanceof \Laravel\Ai\Streaming\Events\StreamEnd) {
                        $usage = $event->usage;
                    }
                }

                // Fallback: get usage from the streamable response if not captured from events
                if ($usage === null) {
                    $usage = $streamResponse->usage;
                }

                $sdkConversationId ??= $streamResponse->conversationId;

                if ($sdkConversationId) {
                    $conversation->update([
                        'metadata' => array_merge($conversation->metadata ?? [], [
                            'ai_conversation_id' => $sdkConversationId,
                        ]),
                    ]);
                }

                // Store the complete message
                $assistantMessage = $conversationManager->addAssistantMessage(
                    conversation: $conversation,
                    content: $responseText,
                    inputTokens: $usage->promptTokens ?? 0,
                    outputTokens: $usage->completionTokens ?? 0,
                );

                // Record token usage
                if (config('filament-copilot.rate_limits.enabled')) {
                    $rateLimitService->recordTokenUsage(
                        user: $user,
                        panelId: $panelId,
                        inputTokens: $usage->promptTokens ?? 0,
                        outputTokens: $usage->completionTokens ?? 0,
                        tenant: $tenant,
                        conversationId: $conversation->id,
                        model: $model,
                        provider: $provider,
                    );
                }

                event(new CopilotResponseReceived(
                    $conversation,
                    $assistantMessage,
                    $usage->promptTokens ?? 0,
                    $usage->completionTokens ?? 0,
                ));

                $this->sendSseEvent('usage', [
                    'input_tokens' => $usage->promptTokens ?? 0,
                    'output_tokens' => $usage->completionTokens ?? 0,
                ]);

                // The persisted assistant message id lets the chat component
                // reference the reply it just streamed — for message feedback,
                // and for anything else that needs to address it later. Older
                // published copies of the chat view simply ignore the payload.
                $this->sendSseEvent('done', [
                    'message_id' => $assistantMessage->id,
                    'approvals' => $pendingApprovals,
                ]);
            } catch (\Throwable $e) {
                // Raw exception messages can carry SQL text and bindings
                // (QueryException), file paths or other internal state — none
                // of which belongs in an end user's chat bubble. Log the full
                // exception server-side under a short correlation id, and only
                // echo the raw message into the stream when the app runs with
                // debug enabled; otherwise the user gets a generic line plus
                // the id, which support can match to the log entry instantly.
                $ref = substr((string) Str::ulid(), -6);
                report(new \RuntimeException("[copilot:{$ref}] ".$e->getMessage(), previous: $e));

                $this->sendSseEvent('error', [
                    'message' => app()->hasDebugModeEnabled()
                        ? $e->getMessage()
                        : __('filament-copilot::filament-copilot.stream_error', ['ref' => $ref]),
                ]);
                $this->sendSseEvent('done', []);
            }
        });
    }

    protected function sseResponse(callable $callback): StreamedResponse
    {
        return new StreamedResponse(function () use ($callback) {
            // Disable output buffering for real-time streaming
            if (ob_get_level()) {
                ob_end_clean();
            }

            $callback();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    protected function sendSseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * The newlines that put a step's text one blank line after the previous
     * step's, counting the newlines either side already brings — so "A.\n"
     * followed by "B." gets one more, and "A.\n\n" gets none. Nothing goes
     * before the first text of a turn.
     */
    protected function stepSeparator(string $previousText, string $nextText): string
    {
        if (trim($previousText) === '') {
            return '';
        }

        $newlines = substr_count(substr($previousText, strlen(rtrim($previousText))), "\n")
            + substr_count(substr($nextText, 0, strlen($nextText) - strlen(ltrim($nextText))), "\n");

        return str_repeat("\n", max(0, 2 - $newlines));
    }
}
