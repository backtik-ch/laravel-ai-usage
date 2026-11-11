## Laravel AI Usage

This package automatically tracks and logs every AI agent call (tokens, cost, duration) in a `laravel/ai` application. It also provides a fluent facade for manual logging and an optional Filament panel integration.

### Auto-Discovery (Zero Config)

If `laravel/ai` is installed, the package listens for `PromptingAgent` and `AgentPrompted` to track prompts from `processing` to `completed`. Failover attempts sharing an invocation ID reuse the same log. With SDK v0.11.0+, `AgentFailed` immediately records terminal failures, including streaming failures; exception details are stored in `response_meta.error.type` and `response_meta.error.message`. Keep `ai-usage:mark-stale-failed` scheduled as a fallback for older SDKs and abruptly terminated processes. To disable automatic logging, set `auto_discover` to `false` in `config/ai-usage.php`.

### Manual Logging

Use the `AiUsage` facade when you need to log AI calls outside of `laravel/ai`:

@verbatim
<code-snippet name="Manual AI usage logging" lang="php">
use BacktikCh\LaravelAiUsage\Facades\AiUsage;

AiUsage::driver('openai')
    ->model('gpt-4o')
    ->label('summarize-article')
    ->tokens(prompt: 512, completion: 256)
    ->duration(milliseconds: 1200)
    ->prompt('Summarize this article...')
    ->response('The article discusses...')
    ->status('completed')
    ->log();
</code-snippet>
@endverbatim

### Attach to an Eloquent Model

@verbatim
<code-snippet name="Attach AI usage to a model" lang="php">
AiUsage::driver('openai')
    ->model('gpt-4o')
    ->tokens(300, 150)
    ->owner($user) // polymorphic — any Eloquent model
    ->log();
</code-snippet>
@endverbatim

### Token Prices & Cost Estimation

Prices (USD per 1M tokens) are configured in `config/ai-usage.php` under the `prices` key. They are **snapshotted into each log row at write time**, so historical costs stay accurate even after you update the config.

To add a new model, add its prices to `config('ai-usage.prices.{driver}.{model}')`. Supported price keys: `prompt`, `completion`, `cache_read`, `cache_write`, `reasoning`.

Read the estimated cost from any log:

```php
$log = AiUsageLog::find(1);
echo $log->estimated_cost; // e.g. 0.003450 — returns null if no price configured
```

### Configuration Quick Reference

| Key | Default | Notes |
|---|---|---|
| `auto_discover` | `true` | Set to `false` to disable automatic `laravel/ai` event listening |
| `log_response_text` | `true` | Set to `false` to skip storing response bodies (saves storage) |
| `log_system_prompt` | `false` | Set to `true` to store system prompts (increases storage) |
| `max_text_length` | `10_000` | Truncate prompt/response text; `null` disables truncation |
| `queue_logs` | `false` | Set to `true` to dispatch log writes as queued jobs |

### Filament Integration (Optional)

Requires `filament/filament`. Register the plugin in your panel provider:

@verbatim
<code-snippet name="Register Filament plugin" lang="php">
use BacktikCh\LaravelAiUsage\Filament\AiUsagePlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(AiUsagePlugin::make());
}
</code-snippet>
@endverbatim

This enables the **AiUsageResource** (browsable log list + detail view) and the **AiUsageStatsWidget** (overview stats on your dashboard).

To add the summary widget (period selector + per-driver/per-model breakdowns) to any page:

@verbatim
<code-snippet name="Add summary widget" lang="php">
use BacktikCh\LaravelAiUsage\Filament\Widgets\AiUsageSummaryWidget;

protected function getHeaderWidgets(): array
{
    return [AiUsageSummaryWidget::class];
}
</code-snippet>
@endverbatim

### Prune Old Records

```bash
php artisan ai-usage:prune --days=90
```

### Status Values

The `AiUsageStatus` enum: `pending`, `processing`, `completed`, `failed`.
