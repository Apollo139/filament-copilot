<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    */

    'provider' => env('FILAMENT_COPILOT_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Default AI Model
    |--------------------------------------------------------------------------
    */

    'model' => env('FILAMENT_COPILOT_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Agent Behavior
    |--------------------------------------------------------------------------
    | An agent class extending CopilotAgent that declares its own #[MaxTokens],
    | #[Temperature] or #[Timeout] attribute keeps it: the attribute takes
    | precedence over the values below.
    */

    'agent' => [
        /*
        |----------------------------------------------------------------
        | Max Tokens
        |----------------------------------------------------------------
        | Maximum output tokens per model call. Reasoning ("thinking") models
        | spend their reasoning tokens out of this same budget, so raise it if
        | answers come back cut off or empty. Null leaves it to the provider.
        */

        'max_tokens' => 4096,

        /*
        |----------------------------------------------------------------
        | Temperature
        |----------------------------------------------------------------
        | Sampling temperature. Null leaves it to the provider — needed for
        | models that reject a custom temperature.
        */

        'temperature' => 0.3,

        /*
        |----------------------------------------------------------------
        | Timeout
        |----------------------------------------------------------------
        | HTTP timeout, in seconds, for each call to the AI provider. Null
        | falls back to 120.
        */

        'timeout' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat History
    |--------------------------------------------------------------------------
    */

    'chat' => [
        'title_auto_generate' => true,

        /*
        |----------------------------------------------------------------
        | Markdown HTML Handling
        |----------------------------------------------------------------
        | How raw HTML inside assistant/model-generated message content is
        | treated when it is rendered as markdown in the chat bubble.
        |
        | Assistant message content is untrusted: it comes from the model,
        | and the model's output can be steered by prompt injection (e.g.
        | text read from a tool result, a document, or a record). Without
        | escaping, HTML/JS embedded in that output (a <script> tag, an
        | onerror handler, ...) would execute in the panel of whoever has
        | the chat open.
        |
        | Valid values (passed straight through to league/commonmark's
        | html_input option):
        | - 'escape' (default): HTML in the model's output is rendered as
        |   visible text instead of being parsed as markup. This is the
        |   safe default.
        | - 'strip': HTML in the model's output is silently removed.
        | - 'allow': HTML in the model's output is rendered as-is. This
        |   restores the pre-hardening behavior and is only safe for
        |   consumers who intentionally have their model emit trusted HTML.
        */

        'html_input' => 'escape',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'enabled' => false,
        'max_messages_per_hour' => 60,
        'max_messages_per_day' => 500,
        'max_tokens_per_hour' => 100000,
        'max_tokens_per_day' => 1000000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Budget
    |--------------------------------------------------------------------------
    */

    'token_budget' => [
        'enabled' => false,
        'warn_at_percentage' => 80,
        'daily_budget' => null,
        'monthly_budget' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    */

    'audit' => [
        'enabled' => true,
        'log_messages' => true,
        'log_tool_calls' => true,
        'log_record_access' => true,
        'log_navigation' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Memory
    |--------------------------------------------------------------------------
    */

    'memory' => [
        'enabled' => true,
        'max_memories_per_user' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Integration
    |--------------------------------------------------------------------------
    */

    'respect_authorization' => true,

    /*
    |--------------------------------------------------------------------------
    | Rate Limit Management UI
    |--------------------------------------------------------------------------
    */

    'management' => [
        'enabled' => false,
        'guard' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Message Feedback
    |--------------------------------------------------------------------------
    | Thumbs up / down on assistant replies. When disabled the buttons are
    | hidden and ratings can no longer be submitted; ratings already stored on
    | copilot_messages.rating are kept and still reported in the management UI.
    */

    'feedback' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quick Actions / Canned Prompts
    |--------------------------------------------------------------------------
    */

    'quick_actions' => [],

    /*
    |--------------------------------------------------------------------------
    | System Prompt
    |--------------------------------------------------------------------------
    */

    'system_prompt' => null,

    /*
    |--------------------------------------------------------------------------
    | Global Tools
    |--------------------------------------------------------------------------
    | Tool classes available on every page across all resources.
    | Each entry should be a class name that extends BaseTool.
    */

    'global_tools' => [],

];
