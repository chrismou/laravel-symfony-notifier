<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chat transports
    |--------------------------------------------------------------------------
    |
    | Named chat transports, as Symfony Notifier DSNs. The "chat" channel uses
    | these for notifiables without their own DSN (or that route to one of
    | these names), and they back the ChatterInterface binding. A message
    | picks one with ->transport('name'); otherwise the first that supports
    | it is used. Empty DSNs are skipped. Install the bridge for each one,
    | e.g. composer require symfony/slack-notifier.
    |
    */

    'chatter_transports' => [
        // 'slack' => env('SLACK_DSN'),
        // 'telegram' => env('TELEGRAM_DSN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS transports
    |--------------------------------------------------------------------------
    |
    | Named SMS transports for the "sms" channel and the TexterInterface
    | binding, chosen the same way as the chat transports.
    |
    */

    'texter_transports' => [
        // 'twilio' => env('TWILIO_DSN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed DSN schemes
    |--------------------------------------------------------------------------
    |
    | The DSN schemes (bridges) any DSN may use, including DSNs that users
    | provide as routes, e.g. ['slack', 'discord', 'telegram']. Null allows
    | every installed bridge. Restrict this when DSNs come from users, as
    | some bridges reach hosts the DSN chooses.
    |
    */

    'allowed_schemes' => null,

];
