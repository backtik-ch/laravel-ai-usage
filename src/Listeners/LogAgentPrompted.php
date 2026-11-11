<?php

namespace BacktikCh\LaravelAiUsage\Listeners;

use BacktikCh\LaravelAiUsage\AiUsageLog;
use BacktikCh\LaravelAiUsage\AiUsageStatus;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Responses\AgentResponse;

class LogAgentPrompted
{
    /**
     * Update the AI usage record with response data when the agent completes.
     */
    public function handle(AgentPrompted $event): void
    {
        try {
            /** @var AgentResponse $response */
            $response = $event->response;
            $usage = $response->usage;
            $meta = $response->meta;

            $record = AiUsageLog::query()
                ->where('request_meta->invocation_id', $event->invocationId)
                ->first();

            $data = [
                'driver' => $meta->provider,
                'model' => $meta->model,
                'prompt_tokens' => $usage->promptTokens ?? $usage->uncachedInputTokens(),
                'completion_tokens' => $usage->completionTokens ?? ($usage->outputTokens - ($usage->reasoningTokens ?? 0)),
                'cache_write_tokens' => $usage->cacheWriteInputTokens ?? 0,
                'cache_read_tokens' => $usage->cacheReadInputTokens ?? 0,
                'reasoning_tokens' => $usage->reasoningTokens ?? 0,
                'status' => AiUsageStatus::Completed->value,
            ];

            // Snapshot prices from config at log time
            $prices = config('ai-usage.prices', [])[$meta->provider][$meta->model] ?? null;
            if (is_array($prices)) {
                $data['prompt_cost_per_million']      = $prices['prompt'] ?? null;
                $data['completion_cost_per_million']  = $prices['completion'] ?? null;
                $data['cache_write_cost_per_million'] = $prices['cache_write'] ?? null;
                $data['cache_read_cost_per_million']  = $prices['cache_read'] ?? null;
                $data['reasoning_cost_per_million']   = $prices['reasoning'] ?? null;
            }

            if (config('ai-usage.log_response_text', true)) {
                $data['response_text'] = $response->text;
                $maxLength = config('ai-usage.max_text_length');
                if ($maxLength !== null) {
                    $data['response_text'] = mb_substr($data['response_text'], 0, $maxLength);
                }
            }

            if ($record) {
                $data['duration_ms'] = (int) $record->created_at->diffInMilliseconds(now());
                $record->update($data);
            } else {
                // No pending record found — create one directly
                $data['label'] = AiUsageLog::labelFromClass($event->prompt->agent::class);
                $data['agent_class'] = $event->prompt->agent::class;

                $record = AiUsageLog::query()->create($data);
            }
        } catch (\Throwable $e) {
            Log::error('[laravel-ai-usage] Failed to log agent prompted.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
