<?php

namespace BacktikCh\LaravelAiUsage\Listeners;

use BacktikCh\LaravelAiUsage\AiUsageLog;
use BacktikCh\LaravelAiUsage\AiUsageStatus;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentFailed;

class LogAgentFailed
{
    /**
     * Mark the usage record as failed when the SDK reports a terminal failure.
     */
    public function handle(AgentFailed $event): void
    {
        try {
            $record = AiUsageLog::query()
                ->where('request_meta->invocation_id', $event->invocationId)
                ->first();

            if ($record?->status === AiUsageStatus::Completed) {
                return;
            }

            $data = [
                'driver' => $event->prompt->provider->name(),
                'model' => $event->prompt->model,
                'status' => AiUsageStatus::Failed->value,
                'response_meta' => array_merge($record?->response_meta ?? [], [
                    'error' => [
                        'type' => $event->exception::class,
                        'message' => $event->exception->getMessage(),
                    ],
                ]),
            ];

            if ($record) {
                $data['duration_ms'] = (int) $record->created_at->diffInMilliseconds(now());
                $record->update($data);
            } else {
                $data['agent_class'] = $event->prompt->agent::class;
                $data['label'] = AiUsageLog::labelFromClass($data['agent_class']);
                $data['request_meta'] = ['invocation_id' => $event->invocationId];

                AiUsageLog::query()->create($data);
            }
        } catch (\Throwable $e) {
            Log::error('[laravel-ai-usage] Failed to log agent failure.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
