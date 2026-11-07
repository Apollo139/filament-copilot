<?php

declare(strict_types=1);

namespace EslamRedaDiv\FilamentCopilot\Agent\Middleware;

use Closure;
use EslamRedaDiv\FilamentCopilot\Agent\Middleware\Concerns\RunsOncePerPrompt;
use EslamRedaDiv\FilamentCopilot\Events\CopilotRateLimitExceeded;
use EslamRedaDiv\FilamentCopilot\Services\RateLimitService;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Prompts\AgentPrompt;

class RateLimitMiddleware
{
    use RunsOncePerPrompt;

    public function __construct(
        protected string $panelId,
        protected Model $user,
        protected ?Model $tenant = null,
    ) {}

    public function handle(AgentPrompt|PendingStep $prompt, Closure $next): mixed
    {
        if (! $this->shouldHandle($prompt) || ! config('filament-copilot.rate_limits.enabled', false)) {
            return $next($prompt);
        }

        /** @var RateLimitService $service */
        $service = app(RateLimitService::class);

        if (! $service->canSendMessage($this->user, $this->panelId, $this->tenant)) {
            event(new CopilotRateLimitExceeded($this->user, $this->panelId, $this->tenant));

            throw new \RuntimeException('Rate limit exceeded. Please try again later.');
        }

        $response = $next($prompt);

        $service->recordMessage($this->user, $this->panelId, $this->tenant);

        return $response;
    }
}
