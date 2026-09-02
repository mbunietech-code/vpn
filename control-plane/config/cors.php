<?php

return [
    'paths' => ['api/*', 'sub/*', 'pay/*', 'webhooks/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'], // tighten to the app's origins in production
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Subscription-Userinfo', 'Profile-Update-Interval'],
    'max_age' => 0,
    'supports_credentials' => false,
];
