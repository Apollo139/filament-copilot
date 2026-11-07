<?php

use EslamRedaDiv\FilamentCopilot\Agent\CopilotAgent;
use EslamRedaDiv\FilamentCopilot\Enums\MessageRole;
use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use EslamRedaDiv\FilamentCopilot\Http\Controllers\StreamController;
use EslamRedaDiv\FilamentCopilot\Models\CopilotMessage;
use EslamRedaDiv\FilamentCopilot\Services\ToolRegistry;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

function swapFilamentForStepTextSeparatorTest($user): void
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
 * Stream the given events through the controller and return the text of
 * every chunk event, in order.
 *
 * @return array<int, string>
 */
function streamChunksForStepTextSeparatorTest(Closure $generator): array
{
    swapFilamentForStepTextSeparatorTest(createTestUser());

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

    $response = app(StreamController::class)->stream(Request::create('/copilot/stream', 'POST', [
        'message' => 'Summarise this week’s orders.',
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

    $output = (string) ob_get_clean();

    preg_match_all('/^event: chunk\ndata: (.+)$/m', $output, $matches);

    return array_map(fn (string $data) => json_decode($data, true)['text'], $matches[1]);
}

function stepTextSeparatorToolEvents(): Generator
{
    $call = new ToolCallData('call-1', 'list_orders', []);

    yield new ToolCall('event-tc', $call, time());
    yield new ToolResult('event-tr', new ToolResultData('call-1', 'list_orders', [], '3 orders'), true, null, time());
}

function persistedAssistantTextForStepTextSeparatorTest(): string
{
    return CopilotMessage::query()
        ->where('role', MessageRole::Assistant->value)
        ->sole()
        ->content;
}

it('separates text from consecutive steps with a blank line', function () {
    $chunks = streamChunksForStepTextSeparatorTest(function () {
        yield new TextDelta('event-1', 'message-1', 'Let me look that up.', time());
        yield from stepTextSeparatorToolEvents();
        yield new TextDelta('event-2', 'message-2', '| Order | Total |', time());
        yield new TextDelta('event-3', 'message-2', "\n|---|---|", time());
    });

    $expected = "Let me look that up.\n\n| Order | Total |\n|---|---|";

    expect(persistedAssistantTextForStepTextSeparatorTest())->toBe($expected)
        ->and(implode('', $chunks))->toBe($expected);
});

it('does not add a separator before the first text of a turn', function () {
    $chunks = streamChunksForStepTextSeparatorTest(function () {
        yield from stepTextSeparatorToolEvents();
        yield new TextDelta('event-1', 'message-1', '## Orders', time());
    });

    expect(persistedAssistantTextForStepTextSeparatorTest())->toBe('## Orders')
        ->and($chunks)->toBe(['## Orders']);
});

it('does not add a separator after the last tool call when no text follows', function () {
    streamChunksForStepTextSeparatorTest(function () {
        yield new TextDelta('event-1', 'message-1', 'Checking now.', time());
        yield from stepTextSeparatorToolEvents();
    });

    expect(persistedAssistantTextForStepTextSeparatorTest())->toBe('Checking now.');
});

it('leaves deltas within a single step untouched', function () {
    $chunks = streamChunksForStepTextSeparatorTest(function () {
        yield new TextDelta('event-1', 'message-1', 'Three orders', time());
        yield new TextDelta('event-2', 'message-1', ' shipped.', time());
    });

    expect(persistedAssistantTextForStepTextSeparatorTest())->toBe('Three orders shipped.')
        ->and($chunks)->toBe(['Three orders', ' shipped.']);
});

it('leaves exactly one blank line between steps, counting the newlines already there', function (array $before, array $after, string $expected) {
    $chunks = streamChunksForStepTextSeparatorTest(function () use ($before, $after) {
        foreach ($before as $index => $delta) {
            yield new TextDelta("event-before-{$index}", 'message-1', $delta, time());
        }

        yield from stepTextSeparatorToolEvents();

        foreach ($after as $index => $delta) {
            yield new TextDelta("event-after-{$index}", 'message-2', $delta, time());
        }
    });

    expect(persistedAssistantTextForStepTextSeparatorTest())->toBe($expected)
        ->and(implode('', $chunks))->toBe($expected);
})->with([
    'previous text ends with a newline' => [["Let me look.\n"], ['Found 3.'], "Let me look.\n\nFound 3."],
    'previous text ends with a blank line' => [["Let me look.\n\n"], ['Found 3.'], "Let me look.\n\nFound 3."],
    'next text starts with a newline' => [['Let me look.'], ["\nFound 3."], "Let me look.\n\nFound 3."],
    'newline on both sides' => [["Let me look.\n"], ["\nFound 3."], "Let me look.\n\nFound 3."],
    'newline-only delta right after the tool' => [['Let me look.'], ["\n", 'Found 3.'], "Let me look.\n\nFound 3."],
    'empty deltas right after the tool' => [['Let me look.'], ['', '', 'Found 3.'], "Let me look.\n\nFound 3."],
    'empty delta before the tool' => [[''], ['Found 3.'], 'Found 3.'],
]);

it('separates text around several consecutive tool calls once', function () {
    $chunks = streamChunksForStepTextSeparatorTest(function () {
        yield new TextDelta('event-1', 'message-1', 'Let me look.', time());
        yield from stepTextSeparatorToolEvents();
        yield from stepTextSeparatorToolEvents();
        yield new TextDelta('event-2', 'message-2', 'Found 3.', time());
        yield from stepTextSeparatorToolEvents();
        yield new TextDelta('event-3', 'message-3', 'Done.', time());
    });

    $expected = "Let me look.\n\nFound 3.\n\nDone.";

    expect(persistedAssistantTextForStepTextSeparatorTest())->toBe($expected)
        ->and(implode('', $chunks))->toBe($expected);
});
