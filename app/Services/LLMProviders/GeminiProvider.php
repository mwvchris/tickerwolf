<?php

namespace App\Services\LLMProviders;

use Illuminate\Support\Facades\Http;
use App\Services\LLMContracts\LLMProviderInterface;

class GeminiProvider implements LLMProviderInterface
{
    public function analyze(string $ticker, string $promptTemplate, array $context = []): array
    {
        $prompt = $this->renderPrompt($promptTemplate, ['ticker'=>strtoupper($ticker)] + $context);

        $response = Http::withToken(config('services.gemini.key'))
            ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent', [
                'contents' => [['parts' => [['text' => $prompt]]]],
            ]);

        return $response->json();
    }

    protected function renderPrompt(string $template, array $data): string {
        foreach ($data as $k=>$v) $template = str_replace('{{'.$k.'}}',$v,$template);
        return $template;
    }

    public function extractStructuredFromText(string $text): ?array {
        $json = $this->findJson($text);
        return $json ? json_decode($json,true) : null;
    }

    protected function findJson(string $text): ?string {
        $start=strrpos($text,'{'); $end=strrpos($text,'}');
        return ($start!==false && $end!==false) ? substr($text,$start,$end-$start+1) : null;
    }
}