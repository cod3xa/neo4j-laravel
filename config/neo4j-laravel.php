<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Neo4j query log channel (optional)
    |--------------------------------------------------------------------------
    |
    | When set to a non-empty string that matches a key in config/logging.php
    | "channels", each in-memory query log entry is written with
    | Log::channel(<name>)->debug(...) after the HTTP request or console
    | command finishes. When empty, entries use the application default logger
    | (Log::debug without a named channel).
    |
    */
    'query_log_channel' => env('NEO4J_QUERY_CHANNEL', ''),

    /*
    |--------------------------------------------------------------------------
    | In-memory query log size
    |--------------------------------------------------------------------------
    |
    | When enableQueryLog() is on, cap how many entries are kept in memory so
    | long-lived workers do not grow without bound. Oldest entries are dropped
    | first. Set to 0 to disable the cap (not recommended for daemons).
    |
    */
    'query_log_max_entries' => (int) env('NEO4J_QUERY_LOG_MAX_ENTRIES', 1000),
];
