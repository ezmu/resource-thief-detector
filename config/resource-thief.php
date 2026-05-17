<?php

return [
    'default_depth' => env('RESOURCE_THIEF_DEPTH', 3),
    'memory_threshold' => env('RESOURCE_THIEF_MEMORY_THRESHOLD', 10240),
    'query_threshold' => env('RESOURCE_THIEF_QUERY_THRESHOLD', 100),
    'profiles_path' => env('RESOURCE_THIEF_PROFILES_PATH', storage_path('profiles')),
    'enable_in_production' => env('RESOURCE_THIEF_PRODUCTION', false),
];
