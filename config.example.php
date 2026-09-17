<?php
/**
 * Copy this file to config.local.php and fill in the real key.
 * config.local.php is gitignored — it never gets committed or auto-deployed,
 * so it must be uploaded to the server once by hand (Hostinger File Manager
 * or FTP), in the same folder as send-quote.php.
 */

define('RESEND_API_KEY', 'your-resend-api-key-here');

// Shared secret for verifying GitHub's deploy webhook (see deploy.php).
// Generate one with: php -r "echo bin2hex(random_bytes(32));"
define('DEPLOY_WEBHOOK_SECRET', 'your-random-webhook-secret-here');
