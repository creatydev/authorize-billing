<?php

/*
|--------------------------------------------------------------------------
| Cashier for Authorize.net
|--------------------------------------------------------------------------
|
| Define your subscriptions and plans here
|
*/

return [
    'authorize' => [
        'login' => env('ADN_API_LOGIN_ID'),
        'key' => env('ADN_TRANSACTION_KEY'),
        'environment' => env('ADN_ENV', 'PRODUCTION'),
        'signature' => env('AUTHORIZE_MERCHANT_SIGNATURE_KEY'),
        'log' => env('ADN_LOG'),
    ],

    // main
    'monthly-10-1' => [
        'name' => 'main',
        'interval' => [
            'length' => 1, // number of instances for billing
            'unit' => 'months' //months, days, years
        ],
        'total_occurances' => 9999, // 9999 means without end date
        'trial_occurances' => 0,
        'amount' => 9.99,
        'trial_amount' => 0,
        'trial_days' => 0,
    ]

];
