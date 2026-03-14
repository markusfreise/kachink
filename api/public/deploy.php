<?php

/**
 * Bitbucket webhook deployment script.
 * Set DEPLOY_SECRET in api/.env and configure the same secret in Bitbucket.
 */

$secret = getenv('DEPLOY_SECRET') ?: '';

// Helll

// Validate secret from query string: /deploy.php?secret=xxx
if (!$secret || ($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    exit('Forbidden');
}

// Only accept POST from Bitbucket
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$root    = '/html/_sites/kachink';
$api     = $root . '/api';
$web     = $root . '/web';
$public  = $api . '/public';
$logFile = $root . '/deploy.log';

function run(string $cmd): string
{
    return shell_exec($cmd . ' 2>&1') ?? '';
}

$log = [];
$log[] = '=== Deploy ' . date('Y-m-d H:i:s') . ' ===';

// Pull latest code
$log[] = run("cd $root && git pull origin main");

// PHP dependencies
$log[] = run("cd $api && composer install --no-interaction --prefer-dist --optimize-autoloader");

// Run migrations
$log[] = run("cd $api && php artisan migrate --force");

// Clear caches
$log[] = run("cd $api && php artisan config:cache && php artisan route:cache && php artisan view:cache");

// Build frontend
$log[] = run("cd $web && npm ci --prefer-offline 2>&1 || npm install");
$log[] = run("cd $web && npm run build-only");

// Copy dist into Laravel public
$log[] = run("cp -r $web/dist/. $public/");

$output = implode("\n", array_filter($log));
file_put_contents($logFile, $output . "\n\n", FILE_APPEND);

http_response_code(200);
echo $output;
