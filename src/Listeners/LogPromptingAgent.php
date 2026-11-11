<?php

namespace BacktikCh\LaravelAiUsage\Listeners;

use BacktikCh\LaravelAiUsage\AiUsageLog;
use BacktikCh\LaravelAiUsage\AiUsageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class LogPromptingAgent
{
    /**
     * Create a processing AI usage record when an agent prompt begins.
     */
    public function handle(object $event): void
    {
        try {
            $prompt = $event->prompt;

            $agentClass = is_object($prompt) && property_exists($prompt, 'agent')
                ? $prompt->agent::class
                : null;

            $data = [
                'agent_class' => $agentClass,
                'label' => $agentClass ? AiUsageLog::labelFromClass($agentClass) : null,
                'driver' => $prompt->provider->name(),
                'model' => $prompt->model,
                'status' => AiUsageStatus::Processing->value,
                'request_meta' => [
                    'invocation_id' => $event->invocationId,
                ],
            ];

            if ($ownerAttributes = $this->resolveAuthenticatedOwnerAttributes()) {
                $data = array_merge($data, $ownerAttributes);
            }

            $maxLength = config('ai-usage.max_text_length');

            if (config('ai-usage.log_system_prompt', false)
                && is_object($prompt)
                && property_exists($prompt, 'agent')
                && method_exists($prompt->agent, 'instructions')) {
                $instructions = (string) $prompt->agent->instructions();
                $data['prompt_text'] = $maxLength !== null
                    ? mb_substr($instructions, 0, $maxLength)
                    : $instructions;
            }

            if (config('ai-usage.log_user_prompt', false)
                && is_object($prompt)
                && property_exists($prompt, 'prompt')) {
                $userPrompt = (string) $prompt->prompt;
                $data['user_prompt_text'] = $maxLength !== null
                    ? mb_substr($userPrompt, 0, $maxLength)
                    : $userPrompt;
            }

            $record = AiUsageLog::query()
                ->where('request_meta->invocation_id', $event->invocationId)
                ->firstOrCreate([], $data);

            if (! $record->wasRecentlyCreated && $record->status === AiUsageStatus::Processing) {
                $record->update([
                    'driver' => $data['driver'],
                    'model' => $data['model'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[laravel-ai-usage] Failed to log prompting agent.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the authenticated user as the optional owner for an automatically discovered usage log.
     */
    private function resolveAuthenticatedOwnerAttributes(): ?array
    {
        if (! config('ai-usage.attach_authenticated_user', false) || ! function_exists('auth')) {
            return null;
        }

        try {
            $owner = auth()->user();

            if ($owner === null) {
                return null;
            }

            if (! $owner instanceof Model) {
                $this->warnAuthenticatedOwnerFailure('The authenticated user is not an Eloquent model.', [
                    'returned_type' => get_debug_type($owner),
                ]);

                return null;
            }

            if ($owner->getKey() === null) {
                $this->warnAuthenticatedOwnerFailure('The authenticated user does not have a primary key.', [
                    'owner_class' => $owner::class,
                ]);

                return null;
            }

            return [
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
            ];
        } catch (\Throwable $e) {
            $this->warnAuthenticatedOwnerFailure('The authenticated user could not be resolved.', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Write an authenticated owner warning without risking the usage log itself.
     */
    private function warnAuthenticatedOwnerFailure(string $reason, array $context = []): void
    {
        try {
            Log::warning('[laravel-ai-usage] Failed to resolve authenticated owner; logging usage without an owner.', array_merge([
                'reason' => $reason,
            ], $context));
        } catch (\Throwable) {
            // Logging failures must not prevent the usage log from being created.
        }
    }
}
