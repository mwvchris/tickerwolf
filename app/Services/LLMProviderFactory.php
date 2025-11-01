<?php

namespace App\Services;

use App\Services\LLMProviders\{OpenAiProvider, GeminiProvider, GrokProvider};
use App\Services\LLMContracts\LLMProviderInterface;
use InvalidArgumentException;

class LLMProviderFactory
{
    public static function make(string $provider): LLMProviderInterface
    {
        return match(strtolower($provider)) {
            'openai' => new OpenAiProvider(),
            'gemini' => new GeminiProvider(),
            'grok'   => new GrokProvider(),
            default  => throw new InvalidArgumentException("Unsupported provider [$provider]"),
        };
    }
}