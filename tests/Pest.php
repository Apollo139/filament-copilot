<?php

use EslamRedaDiv\FilamentCopilot\Tests\TestCase;
use Illuminate\Foundation\Auth\User as Authenticatable;

uses(TestCase::class)->in(__DIR__);

function createTestUser(): Authenticatable
{
    return (new class extends Authenticatable
    {
        protected $table = 'users';
    })::create([
        'name' => 'Test User',
        'email' => 'test-'.uniqid().'@example.com',
        'password' => bcrypt('password'),
    ]);
}

/**
 * Build the usage object a StreamEnd event carries, on any supported
 * laravel/ai line: 1.x streams a TextUsage, earlier lines a plain Usage.
 * Both take the input and output token counts as their first two arguments.
 */
function streamUsage(int $inputTokens, int $outputTokens): object
{
    $class = class_exists(\Laravel\Ai\Responses\Data\TextUsage::class)
        ? \Laravel\Ai\Responses\Data\TextUsage::class
        : \Laravel\Ai\Responses\Data\Usage::class;

    return new $class($inputTokens, $outputTokens);
}
