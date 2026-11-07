<?php

use EslamRedaDiv\FilamentCopilot\Agent\CopilotAgent;
use EslamRedaDiv\FilamentCopilot\Enums\MessageRole;
use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use EslamRedaDiv\FilamentCopilot\Http\Controllers\StreamController;
use EslamRedaDiv\FilamentCopilot\Livewire\CopilotChat;
use EslamRedaDiv\FilamentCopilot\Models\CopilotMessage;
use EslamRedaDiv\FilamentCopilot\Services\ConversationManager;
use EslamRedaDiv\FilamentCopilot\Services\ToolRegistry;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

function swapFilamentForTruncationNoticeTest($user): void
{
    $plugin = new FilamentCopilotPlugin;

    $panel = new class
    {
        public function getId(): string
        {
            return 'admin';
        }
    };

    $guard = new class($user)
    {
        public function __construct(private $user) {}

        public function user()
        {
            return $this->user;
        }
    };

    $manager = new class($guard, $panel, $plugin)
    {
        public function __construct(private $guard, private $panel, private FilamentCopilotPlugin $plugin) {}

        public function getPlugin(string $pluginId): FilamentCopilotPlugin
        {
            return $this->plugin;
        }

        public function auth()
        {
            return $this->guard;
        }

        public function setCurrentPanel(string $panelId): void {}

        public function getCurrentPanel()
        {
            return $this->panel;
        }

        public function getTenant()
        {
            return null;
        }
    };

    app()->instance('filament', $manager);
    Filament::swap($manager);
}

/**
 * Stream a reply that ends with the given finish reason and return the raw
 * SSE body. The usage object is built for whichever laravel/ai line is
 * installed (1.x streams a TextUsage, earlier lines a Usage).
 */
function streamReplyForTruncationNoticeTest(string $text, string $finishReason): string
{
    swapFilamentForTruncationNoticeTest(createTestUser());

    $toolRegistry = Mockery::mock(ToolRegistry::class);
    $toolRegistry->shouldReceive('buildTools')->andReturn([]);
    app()->instance(ToolRegistry::class, $toolRegistry);

    $usageClass = class_exists(\Laravel\Ai\Responses\Data\TextUsage::class)
        ? \Laravel\Ai\Responses\Data\TextUsage::class
        : \Laravel\Ai\Responses\Data\Usage::class;

    $agent = Mockery::mock(CopilotAgent::class);
    $agent->shouldReceive('forPanel')->andReturnSelf();
    $agent->shouldReceive('forUser')->andReturnSelf();
    $agent->shouldReceive('forTenant')->andReturnSelf();
    $agent->shouldReceive('withTools')->andReturnSelf();
    $agent->shouldReceive('withSystemPrompt')->andReturnSelf();
    $agent->shouldReceive('stream')->andReturn(new StreamableAgentResponse(
        invocationId: 'invocation-1',
        generator: function () use ($text, $finishReason, $usageClass) {
            if ($text !== '') {
                yield new TextDelta('event-1', 'message-1', $text, time());
            }

            yield new StreamEnd('event-2', $finishReason, new $usageClass(100, 4096), time());
        },
        meta: new Meta(provider: 'openai', model: 'gpt-4o'),
    ));
    app()->instance(CopilotAgent::class, $agent);

    $response = app(StreamController::class)->stream(Request::create('/copilot/stream', 'POST', [
        'message' => 'Write the full site report.',
        'panel_id' => 'admin',
    ]));

    $initialBufferLevel = ob_get_level();

    ob_start();
    ob_start();
    ob_start();

    $response->sendContent();

    while (ob_get_level() > $initialBufferLevel + 1) {
        ob_end_flush();
    }

    return (string) ob_get_clean();
}

function assistantMessageForTruncationNoticeTest(): CopilotMessage
{
    return CopilotMessage::query()
        ->where('role', MessageRole::Assistant->value)
        ->sole();
}

function renderAssistantBubbleForTruncationNoticeTest(array $msg): string
{
    return view('filament-copilot::components.chat-message', [
        'msg' => ['role' => 'assistant', ...$msg],
    ])->render();
}

it('flags a reply that hit the output token limit', function () {
    $output = streamReplyForTruncationNoticeTest('The excavation of Area A began with', 'length');

    expect($output)->toContain('event: truncated')
        ->toContain(__('filament-copilot::filament-copilot.response_truncated'))
        ->and(strpos($output, 'event: truncated'))->toBeLessThan(strpos($output, 'event: done'))
        ->and(assistantMessageForTruncationNoticeTest()->metadata)->toBe(['finish_reason' => 'length']);
});

it('flags a reply that spent the whole budget reasoning and has no text', function () {
    $output = streamReplyForTruncationNoticeTest('', 'length');

    $message = assistantMessageForTruncationNoticeTest();

    expect($output)->toContain('event: truncated')
        ->and($message->content)->toBe('')
        ->and($message->metadata)->toBe(['finish_reason' => 'length']);
});

it('does not flag a reply that finished normally', function () {
    $output = streamReplyForTruncationNoticeTest('Area A is fully recorded.', 'stop');

    expect($output)->not->toContain('event: truncated')
        ->and(assistantMessageForTruncationNoticeTest()->metadata)->toBe(['finish_reason' => 'stop']);
});

it('marks truncated replies in the reloaded conversation', function () {
    $user = createTestUser();

    /** @var ConversationManager $manager */
    $manager = app(ConversationManager::class);
    $conversation = $manager->create($user, 'admin');
    $manager->addUserMessage($conversation, 'Write the full site report.');
    $manager->addAssistantMessage($conversation, 'The excavation began with', metadata: ['finish_reason' => 'length']);
    $manager->addAssistantMessage($conversation, 'Done.', metadata: ['finish_reason' => 'stop']);
    $manager->addAssistantMessage($conversation, 'Older reply without metadata.');

    $assistantMessages = collect($manager->getMessagesForChat($conversation))->where('role', 'assistant')->values();

    expect($assistantMessages->pluck('truncated')->all())->toBe([true, false, false]);
});

it('marks the streamed reply as truncated in the live chat', function () {
    $user = createTestUser();
    swapFilamentForTruncationNoticeTest($user);

    $chat = new CopilotChat;
    $chat->handleStreamComplete(content: 'The excavation began with', truncated: true);
    $chat->handleStreamComplete(content: 'Done.');

    expect(collect($chat->messages)->pluck('truncated')->all())->toBe([true, null]);
});

it('renders the truncation notice under a truncated reply only', function () {
    $notice = e(__('filament-copilot::filament-copilot.response_truncated'));

    expect(renderAssistantBubbleForTruncationNoticeTest(['content' => 'Cut off mid', 'truncated' => true]))->toContain($notice)
        ->and(renderAssistantBubbleForTruncationNoticeTest(['content' => '', 'truncated' => true]))->toContain($notice)
        ->and(renderAssistantBubbleForTruncationNoticeTest(['content' => 'Complete.', 'truncated' => false]))->not->toContain($notice)
        ->and(renderAssistantBubbleForTruncationNoticeTest(['content' => 'Published view, older payload.']))->not->toContain($notice);
});

it('escapes the truncation notice text', function () {
    app('translator')->addLines(
        ['filament-copilot.response_truncated' => '<img src=x onerror=alert(1)> cut off'],
        'en',
        'filament-copilot',
    );

    $html = renderAssistantBubbleForTruncationNoticeTest(['content' => 'Cut off mid', 'truncated' => true]);

    expect($html)->not->toContain('<img src=x')
        ->toContain('&lt;img src=x onerror=alert(1)&gt; cut off');
});
