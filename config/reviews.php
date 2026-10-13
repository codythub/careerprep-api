<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Review Recovery
    |--------------------------------------------------------------------------
    |
    | A paid review entitlement covers up to `max_attempts` processing attempts
    | until one completes. Failed reviews, and pending reviews whose attempt
    | started more than `stale_pending_minutes` ago, may be retried.
    |
    */

    'max_attempts' => max(1, (int) env('REVIEW_MAX_ATTEMPTS', 3)),

    'stale_pending_minutes' => 10,

];
