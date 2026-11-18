<?php

return [
    'login_per_minute' => (int) env('RATE_LIMIT_LOGIN_PER_MINUTE', 5),
    'registration_per_hour' => (int) env('RATE_LIMIT_REGISTRATION_PER_HOUR', 5),
    'qr_credentials_per_minute' => (int) env('RATE_LIMIT_QR_CREDENTIALS_PER_MINUTE', 10),
    'staff_scans_per_minute' => (int) env('RATE_LIMIT_STAFF_SCANS_PER_MINUTE', 120),
];
