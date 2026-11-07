<?php

declare(strict_types=1);

namespace EslamRedaDiv\FilamentCopilot\Agent\Middleware\Concerns;

use Laravel\Ai\PendingStep;

trait RunsOncePerPrompt
{
    /**
     * Determine whether the middleware should act on this invocation.
     *
     * laravel/ai < 1.0 runs agent middleware once per prompt and passes an
     * AgentPrompt. laravel/ai 1.x runs it around every generation step and
     * passes a PendingStep, so a prompt that calls two tools would hit the
     * middleware three times. Copilot's middleware counts and audits prompts,
     * not steps — on 1.x only the first step of a run is handled, and every
     * later step passes straight through.
     */
    protected function shouldHandle(object $payload): bool
    {
        return ! $payload instanceof PendingStep || $payload->isFirstStep();
    }
}
