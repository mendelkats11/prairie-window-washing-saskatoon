<?php
/**
 * GitHub push webhook receiver — auto-deploys this repo to the folder
 * this file lives in every time you push to GitHub. No Hostinger "web app"
 * slot, no shell access and no git binary required on the server: it just
 * downloads the latest commit as a zip over HTTPS and copies the files in.
 *
 * Setup is one-time:
 *  1. This file + config.local.php must already exist on the server
 *     (the one manual upload — see the README/chat instructions).
 *  2. A GitHub webhook (Settings > Webhooks) on this repo, "application/json",
 *     pointed at this file's public URL, with the same secret as
 *     DEPLOY_WEBHOOK_SECRET below, subscribed to "push" events only.
 *
 * Safety notes:
 *  - Requests are rejected unless their HMAC-SHA256 signature (computed with
 *    DEPLOY_WEBHOOK_SECRET) matches, so only your real GitHub webhook can
 *    trigger a deploy.
 *  - Files are copied/overwritten from the new zip, never deleted, so
 *    config.local.php (which is never in the repo) is always left alone.
 *  - A push that *removes* a file from the repo won't remove it from the
 *    server automatically — clean up stale files by hand if that ever
 *    happens; this keeps the script simple and safe from accidental deletes.
 */

const GITHUB_OWNER = 'mendelkats11';
const GITHUB_REPO = 'prairie-window-washing-saskatoon';
const GITHUB_BRANCH = 'master';
const LOG_FILE = __DIR__ . '/deploy.log';

function deploy_log($message) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function fail($code, $message) {
    deploy_log('FAILED (' . $code . '): ' . $message);
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed.');
}

$configPath = __DIR__ . '/config.local.php';
if (!file_exists($configPath)) {
    fail(500, 'config.local.php is missing on the server.');
}
require $configPath;

if (!defined('DEPLOY_WEBHOOK_SECRET') || DEPLOY_WEBHOOK_SECRET === '') {
    fail(500, 'DEPLOY_WEBHOOK_SECRET is not set in config.local.php.');
}

$rawBody = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if ($signatureHeader === '') {
    fail(403, 'Missing signature header.');
}

$expected = 'sha256=' . hash_hmac('sha256', $rawBody, DEPLOY_WEBHOOK_SECRET);
if (!hash_equals($expected, $signatureHeader)) {
    fail(403, 'Signature verification failed.');
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

// GitHub sends a harmless "ping" event when the webhook is first created.
if ($event === 'ping') {
    deploy_log('Ping received — webhook is connected.');
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => 'pong']);
    exit;
}

if ($event !== 'push') {
    deploy_log('Ignored event: ' . $event);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => 'Ignored non-push event.']);
    exit;
}

$payload = json_decode($rawBody, true);
$ref = $payload['ref'] ?? '';
if ($ref !== 'refs/heads/' . GITHUB_BRANCH) {
    deploy_log('Ignored push to ' . $ref);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => 'Ignored push to non-deploy branch.']);
    exit;
}

deploy_log('Push to ' . GITHUB_BRANCH . ' received. Starting deploy...');

// --- Download the latest commit as a zip ---
$zipUrl = sprintf(
    'https://api.github.com/repos/%s/%s/zipball/%s',
    GITHUB_OWNER,
    GITHUB_REPO,
    GITHUB_BRANCH
);

$tmpZip = tempnam(sys_get_temp_dir(), 'deploy_') . '.zip';

$ch = curl_init($zipUrl);
$fp = fopen($tmpZip, 'w');
curl_setopt_array($ch, [
    CURLOPT_FILE => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => [
        'User-Agent: prairie-window-washing-deploy-script',
        'Accept: application/vnd.github+json',
    ],
    CURLOPT_TIMEOUT => 60,
]);
$downloadOk = curl_exec($ch);
$curlError = curl_error($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fp);

if (!$downloadOk || $statusCode < 200 || $statusCode >= 300) {
    @unlink($tmpZip);
    fail(502, 'Failed to download zip from GitHub (HTTP ' . $statusCode . '): ' . $curlError);
}

// --- Extract it ---
$tmpExtractDir = sys_get_temp_dir() . '/deploy_extract_' . uniqid();
mkdir($tmpExtractDir, 0755, true);

$zip = new ZipArchive();
if ($zip->open($tmpZip) !== true) {
    @unlink($tmpZip);
    fail(500, 'Failed to open downloaded zip archive.');
}
$zip->extractTo($tmpExtractDir);
$zip->close();
@unlink($tmpZip);

// GitHub zipballs contain a single top-level "{owner}-{repo}-{sha}" folder.
$entries = array_values(array_diff(scandir($tmpExtractDir), ['.', '..']));
if (count($entries) !== 1 || !is_dir($tmpExtractDir . '/' . $entries[0])) {
    fail(500, 'Unexpected zip layout from GitHub.');
}
$sourceRoot = $tmpExtractDir . '/' . $entries[0];

// --- Copy files into place (overwrite only, never delete) ---
function deploy_copy_recursive($source, $target) {
    $items = scandir($source);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $srcPath = $source . '/' . $item;
        $dstPath = $target . '/' . $item;

        if (is_dir($srcPath)) {
            if (!is_dir($dstPath)) {
                mkdir($dstPath, 0755, true);
            }
            deploy_copy_recursive($srcPath, $dstPath);
        } else {
            copy($srcPath, $dstPath);
        }
    }
}

deploy_copy_recursive($sourceRoot, __DIR__);

// --- Clean up temp files ---
function deploy_rrmdir($dir) {
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            deploy_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
deploy_rrmdir($tmpExtractDir);

$commitSha = $payload['after'] ?? 'unknown';
deploy_log('Deploy complete. Commit: ' . $commitSha);

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'commit' => $commitSha]);
