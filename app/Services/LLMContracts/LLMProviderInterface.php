<?php

namespace App\Services\LLMContracts;

interface LLMProviderInterface
{
    public function analyze(string $ticker, string $promptTemplate, array $context = []): array;

    public function extractStructuredFromText(string $text): ?array;
}
