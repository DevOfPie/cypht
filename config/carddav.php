<?php

return [
    /*
    |
    | ----------------------------------------
    | Constants used for CardDav communication
    | ----------------------------------------
    |
    */
    'Personal' => [
        'server' => env('CARD_DAV_SERVER', 'http://localhost:5232'),
    ],
    /* Discovery runs at the account root, so never point this at a /manage/
     * URL. Log in with the full mailbox address and an app password. */
    'Purelymail' => [
        'server' => env('PURELYMAIL_CARD_DAV_SERVER', 'https://purelymail.com'),
    ]
];
