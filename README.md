# Laravel AI Usage

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Track and log AI usage (tokens, cost, duration) for any Laravel application, with optional auto-discovery for the `laravel/ai` SDK and an optional Filament panel integration.

## Requirements

- PHP 8.3+
- Laravel 12.x
- Optional: `laravel/ai` v0.11.0+ for immediate failure tracking. Older SDK versions continue to use the stale-record timeout.

## Installation

```bash
composer require backtik-ch/laravel-ai-usage
```

Publish the config file and run the migrations:

```bash
php artisan vendor:publish --tag=ai-usage-config
php artisan migrate
```

## How It Works

This package hooks into the `laravel/ai` SDK lifecycle via these events:

1. **`PromptingAgent`** — fired when an agent prompt starts. The package creates a **processing** record with the agent class, label, and an invocation ID. Failover attempts sharing that ID reuse the same record.
2. **`AgentPrompted`** — fired when the agent completes. The package updates the record to **completed**, fills in token counts, duration, model/driver info, and snapshots the token prices from your config.
3. **`AgentFailed`** — available in SDK v0.11.0+. The package immediately marks a terminal failure as **failed**, storing the provider, model, exception type, and message. If no start record exists, it creates a failed record directly.

Calls are correlated by invocation ID, with no manual logging code required for these SDK events.

If you're not using `laravel/ai`, you can log calls manually via the fluent `AiUsage` facade (see [Manual logging](#manual-logging)).

## Configuration

The config file (`config/ai-usage.php`) exposes the following options:

| Key | Default | Description |
|---|---|---|
| `table` | `ai_usage` | Database table name |
| `queue_logs` | `false` | Dispatch log writes as queued jobs |
| `log_system_prompt` | `false` | Store system prompts in the log |
| `log_response_text` | `true` | Store AI response bodies |
| `max_text_length` | `10_000` | Truncate prompt/response text (chars); `null` disables |
| `stale_timeout_minutes` | `30` | Minutes before a `processing` record is considered stale |
| `auto_discover` | `true` | Auto-listen to `laravel/ai` events |
| `attach_authenticated_user` | `false` | Associate auto-discovered logs with the authenticated user |
| `show_costs` | `true` | Show estimated costs and pricing details in the Filament UI |
| `prices` | *(see below)* | Token prices (USD / 1 M tokens) per driver & model |

### Token prices

Prices are snapshotted into each log row at write time so historical cost estimates remain accurate after you update the config.

```php
// config/ai-usage.php
'prices' => [
    'openai' => [
        'gpt-4o' => ['prompt' => 2.50, 'completion' => 10.00],
        'gpt-4o-mini' => ['prompt' => 0.15, 'completion' => 0.60],
        // ...
    ],
    'anthropic' => [
        'claude-sonnet-4-5' => [
            'prompt' => 3.00, 'completion' => 15.00,
            'cache_read' => 0.30, 'cache_write' => 3.75,
        ],
        // ...
    ],
    'gemini' => [
        'gemini-2.0-flash' => ['prompt' => 0.10, 'completion' => 0.40],
        // ...
    ],
],
```

Supported price keys: `prompt`, `completion`, `cache_read`, `cache_write`, `reasoning`.

The package ships with indicative prices for common OpenAI, Anthropic and Gemini models. Always verify against your provider's current pricing page.

### Hide pricing in Filament

To hide estimated costs and price-related details from the package's Filament resource and summary widget, set:

```php
// config/ai-usage.php
'show_costs' => false,
```

This only affects the UI. The package continues to snapshot configured or manually supplied prices into usage logs, so historical cost data remains available if you later re-enable the setting.

## Usage

> [!TIP]
> **Using `laravel/ai`? You're already done.** If auto-discovery is enabled (default) and `laravel/ai` is installed, every AI agent call is logged automatically — no code needed. The sections below are for **manual logging** or custom integrations.

### Auto-discovery mode

When `auto_discover` is `true` (default) and `laravel/ai` is installed, the package automatically listens for `Laravel\Ai\Events\PromptingAgent` and `Laravel\Ai\Events\AgentPrompted`. A processing record is created when the prompt starts, then updated with tokens, duration, model info, and cost data when the agent finishes. With SDK v0.11.0+, it also listens for `Laravel\Ai\Events\AgentFailed`.

To disable auto-discovery, set `auto_discover` to `false` in `config/ai-usage.php`.

### Automatic failure tracking

With `laravel/ai` v0.11.0 or later, terminal failures are logged immediately, including failures during streaming. The log stores the exception class in `response_meta.error.type` and its message in `response_meta.error.message`, preserving other response metadata. When a processing record exists, its elapsed duration is recorded too.

An intermediate provider failure followed by failover does not mark the call as failed: the record remains processing until the SDK reports success or a terminal failure. A failure event does not overwrite an already completed log.

The SDK remains optional. With older SDK versions, or when a process is killed before it can emit an event, keep scheduling `ai-usage:mark-stale-failed` as a fallback. No new configuration or database migration is required for immediate failure tracking.

### Attach an owner automatically

To associate each automatically discovered log with the authenticated user, enable the following option:

```php
// config/ai-usage.php
'attach_authenticated_user' => true,
```

The package uses the user from Laravel's default authentication guard. Numeric IDs, UUIDs, and ULIDs are supported. Guests and contexts without authentication, such as jobs and commands, are logged without an owner. If the authenticated user cannot be resolved, is not an Eloquent model, or has no primary key, the package writes a warning and creates the usage log without an owner.

### Manual logging

Use the `AiUsage` facade with a fluent builder:

```php
use BacktikCh\LaravelAiUsage\Facades\AiUsage;

AiUsage::driver('openai')
    ->model('gpt-4o')
    ->label('summarize-article')
    ->agentClass(MyAgent::class)          // optional — FQCN of the agent
    ->tokens(
        prompt: 512,
        completion: 256,
        cacheWrite: 0,                    // optional
        cacheRead: 0,                     // optional
        reasoning: 0,                     // optional
    )
    ->duration(milliseconds: 1200)
    ->prompt('Summarize this article...')
    ->response('The article discusses...')
    ->status('completed')
    ->requestMeta(['temperature' => 0.7]) // optional arbitrary metadata
    ->responseMeta(['finish_reason' => 'stop'])
    ->log();
```

### Attach to a model (polymorphic owner)

```php
AiUsage::driver('openai')
    ->model('gpt-4o')
    ->tokens(300, 150)
    ->owner($user) // any Eloquent model
    ->log();
```

### Override prices at log time

```php
AiUsage::driver('openai')
    ->model('gpt-4o')
    ->tokens(300, 150)
    ->costPrices(['prompt' => 2.50, 'completion' => 10.00])
    ->log();
```

If `costPrices()` is not called, prices are resolved automatically from `config('ai-usage.prices')`.

### How cost estimation works

Token prices (USD per 1 million tokens) are **snapshotted from your config at log time** and stored alongside each record. This means historical cost data remains accurate even when you update prices later.

The `AiUsageLog` model exposes an `estimated_cost` computed attribute derived from the stored token counts and prices:

```php
$log = AiUsageLog::find(1);
echo $log->estimated_cost; // e.g. 0.003450
```

Returns `null` when no price data is available (i.e., the model isn't listed in your `prices` config).

To add a new model, simply add its prices to `config('ai-usage.prices.{driver}.{model}')`. Supported price keys: `prompt`, `completion`, `cache_read`, `cache_write`, `reasoning`.

### Status values

Logs use the `AiUsageStatus` enum: `pending`, `processing`, `completed`, `failed`.

## Filament integration

Requires `filament/filament`. Register the plugin in your panel provider:

```php
use BacktikCh\LaravelAiUsage\Filament\AiUsagePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(AiUsagePlugin::make());
}
```

This registers:

- **`AiUsageResource`** — browsable, filterable list of all log entries with a detail view.
- **`AiUsageStatsWidget`** — stats overview: total calls, prompt/completion tokens, average duration, failed count.

### Summary widget (standalone)

`AiUsageSummaryWidget` is a full-width Livewire widget with a period selector and per-driver / per-model token breakdowns. Add it to any page:

```php
use BacktikCh\LaravelAiUsage\Filament\Widgets\AiUsageSummaryWidget;

protected function getHeaderWidgets(): array
{
    return [AiUsageSummaryWidget::class];
}
```

## Artisan Commands

### Prune old records

```bash
php artisan ai-usage:prune --days=90
```

### Mark stale records as failed

On SDK v0.11.0+, `AgentFailed` marks terminal failures immediately. Older SDK versions and abruptly terminated processes can still leave records in `processing`. Keep this command scheduled to mark those stale records as `failed`:

```bash
php artisan ai-usage:mark-stale-failed
```

The timeout is controlled by `stale_timeout_minutes` in your config (default: `30`). You can also override it per command execution:

```bash
php artisan ai-usage:mark-stale-failed --minutes=30
```

Add both commands to your scheduler in `routes/console.php`:

```php
Schedule::command('ai-usage:mark-stale-failed')->hourly();
Schedule::command('ai-usage:prune')->daily();
```

## Testing

```bash
vendor/bin/phpunit
```

## License

MIT — see [LICENSE](LICENSE).
