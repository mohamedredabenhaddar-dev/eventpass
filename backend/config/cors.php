<?php

return [

    /*--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Here you may configure your CORS options. You can allow various origins,
    | headers and methods. CORS stands for Cross-Origin Resource Sharing. This
    | allows browsers to make cross-origin requests to your application while
    | still protecting your application from untrusted origins.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['http://localhost:5173', 'http://127.0.0.1:5173'],

    'allowed_headers' => ['Content-Type', 'Authorization'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
