<?php

use EslamRedaDiv\FilamentCopilot\Agent\CopilotAgent;
use EslamRedaDiv\FilamentCopilot\Enums\AuditAction;
use EslamRedaDiv\FilamentCopilot\Enums\MessageRole;
use EslamRedaDiv\FilamentCopilot\Enums\ToolCallStatus;
use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use EslamRedaDiv\FilamentCopilot\Http\Controllers\StreamController;
use EslamRedaDiv\FilamentCopilot\Models\CopilotAuditLog;
use EslamRedaDiv\FilamentCopilot\Models\CopilotConversation;
use EslamRedaDiv\FilamentCopilot\Models\CopilotMessage;
use EslamRedaDiv\FilamentCopilot\Models\CopilotTokenUsage;
use EslamRedaDiv\FilamentCopilot\Models\CopilotToolCall;
use EslamRedaDiv\FilamentCopilot\Services\RateLimitService;
use EslamRedaDiv\FilamentCopilot\Services\ToolRegistry;
use Filament\Facades\Filament;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Tools\Request as ToolRequest;

/*
 * These tests exercise the parts of copilot that differ between the
 * laravel/ai 0.x and 1.x lines, and are meant to pass on both.
 */

class LaravelAiCompatLookupTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Looks something up.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        return 'found it';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class LaravelAiCompatApprovalTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public static int $runs = 0;

    public function description(): Stringable|string
    {
        return 'Changes something, once approved.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        static::$runs++;

        return 'changed it';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

/**
 * The real agent, minus the panel discovery its instructions need.
 */
class LaravelAiCompatAgent extends CopilotAgent
{
    public function instructions(): Stringable|string
    {
        return 'You are a test agent.';
    }
}

function swapFilamentForLaravelAiCompatTest($user, string $panelId = 'admin'): void
{
    $plugin = new FilamentCopilotPlugin;

    $panel = new class($panelId)
    {
        public function __construct(private string $id) {}

        public function getId(): string
        {
            return $this->id;
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

function captureSseOutputForLaravelAiCompatTest(array $body): string
{
    $response = app(StreamController::class)->stream(Request::create('/copilot/stream', 'POST', $body + [
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

function streamFakeResponseForLaravelAiCompatTest(Closure $generator): string
{
    $toolRegistry = Mockery::mock(ToolRegistry::class);
    $toolRegistry->shouldReceive('buildTools')->andReturn([]);
    app()->instance(ToolRegistry::class, $toolRegistry);

    $agent = Mockery::mock(CopilotAgent::class);
    $agent->shouldReceive('forPanel')->andReturnSelf();
    $agent->shouldReceive('forUser')->andReturnSelf();
    $agent->shouldReceive('forTenant')->andReturnSelf();
    $agent->shouldReceive('withTools')->andReturnSelf();
    $agent->shouldReceive('withSystemPrompt')->andReturnSelf();
    $agent->shouldReceive('stream')->andReturn(new StreamableAgentResponse(
        invocationId: 'invocation-1',
        generator: $generator,
        meta: new Meta(provider: 'openai', model: 'gpt-4o'),
    ));
    app()->instance(CopilotAgent::class, $agent);

    return captureSseOutputForLaravelAiCompatTest([
        'message' => 'How many orders shipped today?',
    ]);
}

it('runs the rate-limit and audit middleware once per prompt, however many steps the run takes', function () {
    $this->app->register(AiServiceProvider::class);
    $this->loadMigrationsFrom(dirname((new ReflectionClass(AiServiceProvider::class))->getFileName(), 2).'/database/migrations');

    config()->set('filament-copilot.rate_limits.enabled', true);
    config()->set('filament-copilot.audit.enabled', true);
    config()->set('filament-copilot.audit.log_messages', true);

    $rateLimits = Mockery::mock(RateLimitService::class);
    $rateLimits->shouldReceive('canSendMessage')->once()->andReturnTrue();
    $rateLimits->shouldReceive('recordMessage')->once();
    app()->instance(RateLimitService::class, $rateLimits);

    // Two generation steps: the model calls a tool, then answers.
    LaravelAiCompatAgent::fake([
        new ToolCallData('call-1', 'LaravelAiCompatLookupTool', []),
        'There are 3 orders.',
    ]);

    $user = createTestUser();

    $agent = app(LaravelAiCompatAgent::class)
        ->forPanel('admin')
        ->forTenant(null)
        ->withTools([new LaravelAiCompatLookupTool])
        ->forUser($user);

    $text = '';

    foreach ($agent->stream('How many orders?') as $event) {
        if ($event instanceof TextDelta) {
            $text .= $event->delta;
        }
    }

    expect($text)->toBe('There are 3 orders.')
        ->and(CopilotAuditLog::query()->where('action', AuditAction::MessageSent)->count())->toBe(1)
        ->and(CopilotAuditLog::query()->where('action', AuditAction::ResponseReceived)->count())->toBe(1);
});

it('records the token usage reported by the stream', function () {
    $user = createTestUser();
    swapFilamentForLaravelAiCompatTest($user);

    $output = streamFakeResponseForLaravelAiCompatTest(function () {
        yield new TextDelta('event-1', 'message-1', 'Three orders shipped.', time());
        yield new StreamEnd('event-2', 'stop', streamUsage(120, 45), time());
    });

    $assistantMessage = CopilotMessage::query()
        ->where('role', MessageRole::Assistant->value)
        ->firstOrFail();

    expect($assistantMessage->input_tokens)->toBe(120)
        ->and($assistantMessage->output_tokens)->toBe(45)
        ->and($output)->toContain('"input_tokens":120')
        ->toContain('"output_tokens":45');
});

it('skips preliminary sub-agent tool results', function () {
    $user = createTestUser();
    swapFilamentForLaravelAiCompatTest($user);

    $result = fn (string $output, bool $preliminary) => new ToolResult(
        'event-'.($preliminary ? 'p' : 'f'),
        new ToolResultData('call-1', 'research_agent', [], $output),
        true,
        null,
        time(),
        preliminary: $preliminary,
    );

    $output = streamFakeResponseForLaravelAiCompatTest(function () use ($result) {
        yield $result('working...', true);
        yield $result('final answer', false);
        yield new StreamEnd('event-2', 'stop', streamUsage(1, 1), time());
    });

    expect(substr_count($output, 'event: tool_result'))->toBe(1)
        ->and($output)->toContain('final answer')
        ->not->toContain('working...')
        ->and(CopilotToolCall::query()->sole()->tool_output)->toBe('final answer');
})->skip(
    ! property_exists(ToolResult::class, 'preliminary'),
    'Preliminary tool results only exist on laravel/ai 1.x.',
);

it('rejects a rate-limited user\'s approval before the approved tool runs', function () {
    $this->app->register(AiServiceProvider::class);
    $this->loadMigrationsFrom(dirname((new ReflectionClass(AiServiceProvider::class))->getFileName(), 2).'/database/migrations');

    config()->set('filament-copilot.rate_limits.enabled', true);
    config()->set('filament-copilot.audit.enabled', true);
    config()->set('filament-copilot.audit.log_messages', true);
    config()->set('ai.conversations.generate_title', false);
    config()->set('ai.providers.anthropic.key', 'test-key');

    LaravelAiCompatApprovalTool::$runs = 0;

    // The real provider round trip, so the resume would go through laravel/ai's
    // own approval handling: the model asks for the tool, then answers.
    Http::fake(['api.anthropic.com/*' => Http::sequence([
        Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'LaravelAiCompatApprovalTool', 'input' => (object) []]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
        Http::response([
            'id' => 'msg_2',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => 'Done.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ])]);

    $user = createTestUser();
    swapFilamentForLaravelAiCompatTest($user);
    FilamentCopilotPlugin::get()->provider('anthropic')->model('claude-sonnet-4-6');

    $toolRegistry = Mockery::mock(ToolRegistry::class);
    $toolRegistry->shouldReceive('buildTools')->andReturn([new LaravelAiCompatApprovalTool]);
    app()->instance(ToolRegistry::class, $toolRegistry);
    app()->bind(CopilotAgent::class, LaravelAiCompatAgent::class);

    // The run pauses for approval while the user is still within limits.
    $paused = app(LaravelAiCompatAgent::class)
        ->forPanel('admin')
        ->forTenant(null)
        ->withTools([new LaravelAiCompatApprovalTool])
        ->forUser($user)
        ->prompt('Change it', provider: 'anthropic');

    expect($paused->pendingApprovals)->toHaveCount(1);

    $conversation = CopilotConversation::create([
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'panel_id' => 'admin',
        'title' => 'Change it',
        'metadata' => ['ai_conversation_id' => $paused->conversationId],
    ]);
    $toolCall = $conversation->messages()
        ->create(['role' => MessageRole::User, 'content' => 'Change it'])
        ->toolCalls()
        ->create([
            'provider_id' => 'toolu_1',
            'tool_name' => 'LaravelAiCompatApprovalTool',
            'tool_input' => [],
            'status' => ToolCallStatus::Pending,
            'requires_approval' => true,
        ]);

    $auditRows = CopilotAuditLog::query()->count();

    // Then the user hits their limit, and approves the paused tool.
    app(RateLimitService::class)->blockUser($user, 'admin', 'Over the limit');

    $output = captureSseOutputForLaravelAiCompatTest([
        'conversation_id' => $conversation->id,
        'decisions' => ['toolu_1' => ['action' => 'approve']],
    ]);

    expect($output)->toContain(json_encode(__('filament-copilot::filament-copilot.rate_limit_exceeded'), JSON_UNESCAPED_UNICODE))
        ->and(LaravelAiCompatApprovalTool::$runs)->toBe(0)
        ->and(Http::recorded())->toHaveCount(1)
        ->and($toolCall->fresh()->status)->toBe(ToolCallStatus::Pending)
        ->and(CopilotAuditLog::query()->count())->toBe($auditRows)
        ->and(CopilotTokenUsage::query()->count())->toBe(0);
});
