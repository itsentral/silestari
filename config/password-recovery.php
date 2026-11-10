<?php

return [
    'enabled' => env('PASSWORD_RECOVERY_ENABLED', false),
    'url' => env('PASSWORD_RESET_URL', env('APP_URL', 'http://localhost')),
];
