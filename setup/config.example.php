<?php

// Reference only. The REAL file belongs at ../private/config.php, beside public_html.
// Use the separate ChapterScript database credentials.
return [
    'database' => [
        'host' => 'localhost',
        'name' => 'YOUR_FULL_DATABASE_NAME',
        'username' => 'YOUR_FULL_DATABASE_USERNAME',
        'password' => 'YOUR_DATABASE_PASSWORD',
    ],
    // Generate once: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    'rate_limit_secret' => 'REPLACE_WITH_64_RANDOM_HEX_CHARACTERS',

    // Defaults below are optional. Local tests override them in a temporary config.
    'allowed_origins' => [
        'https://chapterscript.com',
        'https://www.chapterscript.com',
        // Add your exact HTTPS temporary origin here, without a trailing slash.
        // 'https://YOUR-TEMPORARY-HOST',
    ],
    // 'rate_limits' => [900 => 5, 86400 => 20],
];
