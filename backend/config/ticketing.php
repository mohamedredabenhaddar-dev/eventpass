<?php

return [
    'reservation_expiration_minutes' => max(1, (int) env('RESERVATION_EXPIRATION_MINUTES', 15)),
    'currency' => strtoupper((string) env('EVENTPASS_CURRENCY', 'MAD')),
];
