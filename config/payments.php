<?php

return [
    'timezone' => env('PAYMENT_TIMEZONE', 'Europe/Moscow'),
    'accept_from' => env('PAYMENT_ACCEPT_FROM', '07:00'),
    'accept_until' => env('PAYMENT_ACCEPT_UNTIL', '17:00'),
];
