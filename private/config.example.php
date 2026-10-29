<?php
declare(strict_types=1);

// Copy this file to config.php in the same private directory.
// On the server that directory is $HOME/gap-app-private/, outside the website.
// Deploy never creates, copies, or deletes config.php.
//
// GEMINI_API_KEY stays in this private file or in the server environment.
// Do not commit config.php, and do not put the key anywhere inside the website.
// FEEDBACK_TO is where feedback email is delivered. The default is the public
// Better Schools address. Change it in the private config.php only.

return [
    'GEMINI_API_KEY' => 'REPLACE_WITH_YOUR_KEY',
    'GEMINI_MODEL' => 'gemini-3.5-flash-lite',
    'FEEDBACK_TO' => 'info@betterschools.com.au',
    'FEEDBACK_FROM' => 'noreply@betterschools.com.au',
];
