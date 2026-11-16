<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Lobby-Chat-Mitschnitt des Game-Servers (JSON Lines), siehe
    // AdminController::lobbyChat().
    'lobby_chat_log' => env('LOBBY_CHAT_LOG', base_path('../images/avatars/game/chatlog/lobby-chat.log')),

    // Lokale GeoIP-Datenbank (DB-IP City Lite, CC BY 4.0), aktualisiert
    // monatlich per `php artisan geoip:update`.
    'geoip' => [
        'path' => env('GEOIP_DB_PATH', storage_path('app/geoip/dbip-city-lite.mmdb')),
    ],

    // Admin-Bot für Live-Aktionen über die WebSocket-Schnittstelle des
    // Game-Servers, siehe App\Services\GameServerAdminClient. Host/Port/Pfad
    // entsprechen dem nginx-Upstream von /pthlive. Das Konto muss in
    // admin_player stehen (wird vom Server nur beim Start gelesen).
    'game_server_admin' => [
        'host'     => env('PTH_ADMIN_BOT_HOST', '10.7.7.150'),
        'port'     => (int) env('PTH_ADMIN_BOT_PORT', 7235),
        'tls'      => (bool) env('PTH_ADMIN_BOT_TLS', false),
        'resource' => env('PTH_ADMIN_BOT_RESOURCE', '/pthlive'),
        'origin'   => env('PTH_ADMIN_BOT_ORIGIN', 'http://www.pokerth.net'),
        'nick'     => env('PTH_ADMIN_BOT_NICK', ''),
        'password' => env('PTH_ADMIN_BOT_PASSWORD', ''),
        // Qt-Widget 2.1.9 = MIN_BUILD_ID_QT_WIDGET, wird vom Server sicher angenommen.
        'build_id' => (int) env('PTH_ADMIN_BOT_BUILD_ID', 0x01020109),
        'timeout'  => (float) env('PTH_ADMIN_BOT_TIMEOUT', 8),
    ],

];
