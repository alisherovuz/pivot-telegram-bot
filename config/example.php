<?php
// Copy to config/local.php, outside the public document root. Never commit secrets.
return [
    'dsn' => 'mysql:host=localhost;dbname=CPANELUSER_pivot;charset=utf8mb4',
    'db_user' => 'CPANELUSER_pivot',
    'db_password' => 'REPLACE_PRIVATELY',
    'bot_token' => 'REPLACE_PRIVATELY',
    'webhook_secret' => 'REPLACE_WITH_RANDOM_64_HEX_CHARACTERS',
    'base_url' => 'https://bot.example.uz',
    'channel' => '@pivotuzbekistan',
    'storage' => dirname(__DIR__) . '/storage',
    'session_secure' => true,
    'telegram_mode' => 'live', // tests use fake; never use fake in production
];
