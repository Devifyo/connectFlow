<?php

return [
    'url' => env('DEEPSTACK_URL', 'http://deepstack:5000'),
    'api_key' => env('DEEPSTACK_API_KEY', ''),
    'min_confidence' => (float) env('DEEPSTACK_MIN_CONFIDENCE', 0.65),
];
