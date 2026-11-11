<?php

namespace BacktikCh\LaravelAiUsage\Tests;

use BacktikCh\LaravelAiUsage\AiUsageLog;
use BacktikCh\LaravelAiUsage\AiUsageStatus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\Usage;
use Mockery;
use RuntimeException;

class AgentFailureLoggingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), AiServiceProvider::class];
    }

    public function test_sdk_failures_are_logged_for_prompts_and_streams_without_swallowing_the_exception(): void
    {
        $agent = $this->agent();

        foreach (['prompt', 'stream'] as $method) {
            $exception = new RuntimeException('Provider unavailable');
            $agent::fake(fn () => throw $exception);

            try {
                $response = $agent->{$method}('Hello', provider: 'openai', model: 'gpt-4o');

                if ($method === 'stream') {
                    iterator_to_array($response);
                }

                $this->fail('The SDK exception should reach the caller.');
            } catch (RuntimeException $caught) {
                $this->assertSame($exception, $caught);
            }

            $log = AiUsageLog::query()->latest('id')->firstOrFail();
            $this->assertSame(AiUsageStatus::Failed, $log->status);
            $this->assertSame('openai', $log->driver);
            $this->assertSame('gpt-4o', $log->model);
            $this->assertSame($agent::class, $log->agent_class);
            $this->assertNotEmpty($log->request_meta['invocation_id']);
            $this->assertSame([
                'type' => RuntimeException::class,
                'message' => 'Provider unavailable',
            ], $log->response_meta['error']);
        }

        $this->assertSame(2, AiUsageLog::query()->count());
    }

    public function test_failure_updates_only_the_matching_log_and_preserves_its_metadata(): void
    {
        $this->travelTo(now()->startOfSecond());
        $prompt = $this->prompt();
        Event::dispatch(new PromptingAgent('failed-call', $prompt));
        $log = AiUsageLog::query()->firstOrFail();
        $log->update(['response_meta' => ['custom' => 'preserved']]);
        $unrelated = AiUsageLog::query()->create(['status' => 'processing']);

        $this->travel(2)->seconds();
        Event::dispatch(new AgentFailed('failed-call', $prompt, new RuntimeException('Failed')));

        $this->assertSame(AiUsageStatus::Failed, $log->fresh()->status);
        $this->assertSame(2000, $log->fresh()->duration_ms);
        $this->assertSame('preserved', $log->fresh()->response_meta['custom']);
        $this->assertSame(AiUsageStatus::Processing, $unrelated->fresh()->status);
        $this->assertSame(2, AiUsageLog::query()->count());
    }

    public function test_failover_keeps_one_processing_log_and_can_complete_successfully(): void
    {
        $this->travelTo(now()->startOfSecond());
        $usage = class_exists(TextUsage::class)
            ? new TextUsage(inputTokens: 150, outputTokens: 50, cacheReadInputTokens: 20, cacheWriteInputTokens: 30, reasoningTokens: 10)
            : new Usage(promptTokens: 100, completionTokens: 40, cacheWriteInputTokens: 30, cacheReadInputTokens: 20, reasoningTokens: 10);
        $agent = $this->agent();
        $attempts = 0;
        $startedAt = null;
        $agent::fake(function ($text, $attachments, $provider, $model) use (&$attempts, &$startedAt, $usage) {
            $log = AiUsageLog::query()->firstOrFail();
            $this->assertSame(AiUsageStatus::Processing, $log->status);
            $this->assertSame($provider->name(), $log->driver);
            $this->assertSame($model, $log->model);

            if (++$attempts === 1) {
                $startedAt = $log->created_at->toISOString();
                $log->update([
                    'label' => 'custom-label',
                    'request_meta' => array_merge($log->request_meta, ['custom' => 'preserved']),
                ]);
                $this->travel(2)->seconds();

                throw RateLimitedException::forProvider('openai');
            }

            $this->assertSame($startedAt, $log->created_at->toISOString());
            $this->assertSame('custom-label', $log->label);
            $this->assertSame('preserved', $log->request_meta['custom']);

            return new AgentResponse('fake', 'Hello!', $usage, new Meta('anthropic', 'claude-test'));
        });

        Event::listen(AgentFailedOver::class, function (): void {
            $this->assertSame(AiUsageStatus::Processing, AiUsageLog::query()->firstOrFail()->status);
        });
        $failures = 0;
        Event::listen(AgentFailed::class, function () use (&$failures): void {
            $failures++;
        });

        $response = $agent->prompt('Hello', provider: ['openai' => 'gpt-4o', 'anthropic' => 'claude-test']);

        $log = AiUsageLog::query()->firstOrFail();
        $this->assertSame(2, $attempts);
        $this->assertSame(0, $failures);
        $this->assertSame(1, AiUsageLog::query()->count());
        $this->assertSame(AiUsageStatus::Completed, $log->status);
        $this->assertSame('anthropic', $log->driver);
        $this->assertSame('claude-test', $log->model);
        $this->assertSame(2000, $log->duration_ms);
        $this->assertSame(100, $log->prompt_tokens);
        $this->assertSame(40, $log->completion_tokens);
        $this->assertSame(30, $log->cache_write_tokens);
        $this->assertSame(20, $log->cache_read_tokens);
        $this->assertSame(10, $log->reasoning_tokens);
        $this->assertSame(200, $log->totalTokens());

        Event::dispatch(new AgentFailed($response->invocationId, $this->prompt(), new RuntimeException('Late failure')));
        $this->assertSame(AiUsageStatus::Completed, $log->fresh()->status);
    }

    public function test_start_events_preserve_finalized_logs(): void
    {
        foreach ([AiUsageStatus::Completed, AiUsageStatus::Failed] as $status) {
            $log = AiUsageLog::query()->create([
                'status' => $status,
                'driver' => 'original-provider',
                'model' => 'original-model',
                'request_meta' => ['invocation_id' => $status->value],
            ]);

            Event::dispatch(new PromptingAgent($status->value, $this->prompt()));

            $log->refresh();
            $this->assertSame($status, $log->status);
            $this->assertSame('original-provider', $log->driver);
            $this->assertSame('original-model', $log->model);
        }

        $this->assertSame(2, AiUsageLog::query()->count());
    }

    public function test_duplicate_failure_events_do_not_create_duplicate_logs(): void
    {
        $event = new AgentFailed('failed-call', $this->prompt(), new RuntimeException('Failed'));
        Event::dispatch($event);
        Event::dispatch($event);

        $this->assertSame(1, AiUsageLog::query()->count());
        $this->assertSame(AiUsageStatus::Failed, AiUsageLog::query()->firstOrFail()->status);
    }

    public function test_success_with_unreported_optional_usage_counts_is_logged(): void
    {
        $agent = $this->agent();
        $agent::fake(['Hello!']);
        $agent->prompt('Hello', provider: 'openai', model: 'gpt-4o');

        $log = AiUsageLog::query()->firstOrFail();
        $this->assertSame(AiUsageStatus::Completed, $log->status);
        $this->assertSame(0, $log->totalTokens());
    }

    public function test_a_logging_error_does_not_escape_the_failure_listener(): void
    {
        config(['ai-usage.table' => 'missing_ai_usage']);
        Log::shouldReceive('error')->once()->with(
            '[laravel-ai-usage] Failed to log agent failure.',
            Mockery::on(fn (array $context) => isset($context['error'])),
        );

        Event::dispatch(new AgentFailed('failed-call', $this->prompt(), new RuntimeException('Failed')));
        config(['ai-usage.table' => 'ai_usage']);
    }

    private function prompt(): AgentPrompt
    {
        $provider = Mockery::mock(OpenAiProvider::class);
        $provider->shouldReceive('name')->andReturn('openai');

        return new AgentPrompt(Mockery::mock(Agent::class), 'Hello', [], $provider, 'gpt-4o');
    }

    private function agent(): Agent
    {
        return new class implements Agent
        {
            use Promptable;

            public function instructions(): string
            {
                return 'You are a test agent.';
            }
        };
    }
}
