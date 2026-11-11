<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Table Name
    |--------------------------------------------------------------------------
    |
    | The database table used to store AI usage logs. Change this before
    | running the migration if you need a different table name.
    |
    */
    'table' => 'ai_usage',

    /*
    |--------------------------------------------------------------------------
    | Queue Logs
    |--------------------------------------------------------------------------
    |
    | When true, AI usage records are dispatched as queued jobs instead of
    | being written synchronously. Useful for high-throughput applications.
    |
    */
    'queue_logs' => false,

    /*
    |--------------------------------------------------------------------------
    | Log System Prompt
    |--------------------------------------------------------------------------
    |
    | When true, the full system prompt (agent instructions) is stored in
    | prompt_text. This can significantly increase storage. Default: false.
    |
    */
    'log_system_prompt' => false,

    /*
    |--------------------------------------------------------------------------
    | Log User Prompt
    |--------------------------------------------------------------------------
    |
    | When true, the user's prompt message is stored in user_prompt_text.
    | Set to false to avoid storing potentially sensitive user input.
    |
    */
    'log_user_prompt' => false,

    /*
    |--------------------------------------------------------------------------
    | Log Response Text
    |--------------------------------------------------------------------------
    |
    | When false, the response_text column is left null. Set to false to save
    | storage if you only care about token counts and metadata.
    |
    */
    'log_response_text' => true,

    /*
    |--------------------------------------------------------------------------
    | Maximum Response/Prompt Text Length
    |--------------------------------------------------------------------------
    |
    | Truncate prompt_text and response_text beyond this character count to
    | avoid excessively large rows. Set to null for no truncation.
    |
    */
    'max_text_length' => 10_000,

    /*
    |--------------------------------------------------------------------------
    | Stale Record Timeout
    |--------------------------------------------------------------------------
    |
    | Processing records older than this many minutes are considered stale
    | when no terminal event was received, for example with an older SDK
    | or an abruptly terminated process. SDK v0.11.0+ failures are logged
    | immediately through AgentFailed when auto-discovery is enabled.
    | Run `ai-usage:mark-stale-failed` on a schedule to clean these up.
    |
    */
    'stale_timeout_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Auto-Discovery Mode
    |--------------------------------------------------------------------------
    |
    | When true and laravel/ai is installed, the package automatically listens
    | for Laravel\Ai\Events\AgentPrompted and logs all AI agent calls.
    | Set to false to disable auto mode and use only manual logging.
    |
    */
    'auto_discover' => true,

    /*
    |--------------------------------------------------------------------------
    | Attach Authenticated User
    |--------------------------------------------------------------------------
    |
    | When true, automatically discovered AI usage logs are associated with
    | the authenticated user from Laravel's default guard. Guests and contexts
    | without authentication (such as jobs or commands) are logged without an
    | owner.
    |
    */
    'attach_authenticated_user' => false,

    /*
    |--------------------------------------------------------------------------
    | Show Estimated Costs
    |--------------------------------------------------------------------------
    |
    | When false, pricing and estimated-cost information is hidden from the
    | package's Filament resource and summary widget. Price snapshots continue
    | to be stored so costs can be shown again later without losing history.
    |
    */
    'show_costs' => true,

    /*
    |--------------------------------------------------------------------------
    | Token Prices (USD per 1 million tokens)
    |--------------------------------------------------------------------------
    |
    | Used to estimate the cost of each AI call at log time. Prices are stored
    | as a snapshot in each log row so historical costs remain accurate even
    | when you update this config.
    |
    | Prices are indicative and based on publicly available pricing.
    | Always verify against your provider's current pricing page and update
    | this config accordingly. null = no price configured (cost unknown).
    |
    | Structure: 'prices' => [ driver => [ model => [ type => price ] ] ]
    |
    */
    'prices' => [

        'openai' => [
            'gpt-4o' => [
                'prompt'     => 2.50,
                'completion' => 10.00,
            ],
            'gpt-4o-mini' => [
                'prompt'     => 0.15,
                'completion' => 0.60,
            ],
            'gpt-4.1' => [
                'prompt'     => 2.00,
                'completion' => 8.00,
            ],
            'gpt-4.1-mini' => [
                'prompt'     => 0.40,
                'completion' => 1.60,
            ],
            'o3' => [
                'prompt'     => 10.00,
                'completion' => 40.00,
                'reasoning'  => 40.00,
            ],
            'o4-mini' => [
                'prompt'     => 1.10,
                'completion' => 4.40,
                'reasoning'  => 4.40,
            ],
        ],

        'anthropic' => [
            'claude-opus-4-5' => [
                'prompt'      => 15.00,
                'completion'  => 75.00,
                'cache_read'  => 1.50,
                'cache_write' => 18.75,
            ],
            'claude-sonnet-4-5' => [
                'prompt'      => 3.00,
                'completion'  => 15.00,
                'cache_read'  => 0.30,
                'cache_write' => 3.75,
            ],
            'claude-haiku-3-5' => [
                'prompt'      => 0.80,
                'completion'  => 4.00,
                'cache_read'  => 0.08,
                'cache_write' => 1.00,
            ],
        ],

        'gemini' => [
            'gemini-2.5-pro' => [
                'prompt'     => 1.25,
                'completion' => 10.00,
            ],
            'gemini-2.0-flash' => [
                'prompt'     => 0.10,
                'completion' => 0.40,
            ],
            'gemini-1.5-flash' => [
                'prompt'     => 0.075,
                'completion' => 0.30,
            ],
        ],

    ],

];
