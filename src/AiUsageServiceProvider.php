<?php

namespace BacktikCh\LaravelAiUsage;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AiUsageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/ai-usage.php',
            'ai-usage'
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/ai-usage.php' => config_path('ai-usage.php'),
        ], 'ai-usage-config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'ai-usage');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\PruneCommand::class,
                Commands\MarkStaleAsFailedCommand::class,
            ]);
        }

        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::component(
                'backtik-ch.laravel-ai-usage.filament.widgets.ai-usage-summary-widget',
                Filament\Widgets\AiUsageSummaryWidget::class
            );
        }

        if ($this->autoDiscoverEnabled()) {
            Event::listen(
                \Laravel\Ai\Events\PromptingAgent::class,
                Listeners\LogPromptingAgent::class,
            );

            Event::listen(
                \Laravel\Ai\Events\AgentPrompted::class,
                Listeners\LogAgentPrompted::class,
            );

            if (class_exists(\Laravel\Ai\Events\AgentFailed::class)) {
                Event::listen(
                    \Laravel\Ai\Events\AgentFailed::class,
                    Listeners\LogAgentFailed::class,
                );
            }
        }
    }

    private function autoDiscoverEnabled(): bool
    {
        return config('ai-usage.auto_discover', true)
            && class_exists(\Laravel\Ai\Events\AgentPrompted::class);
    }
}
