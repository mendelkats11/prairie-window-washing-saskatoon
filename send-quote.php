<?php
/**
 * Handles the "Get a Free Quote" form submission and relays it to Resend.
 *
 * The Resend API key is a secret and must never reach the browser. It is
 * kept out of this file and out of git entirely — see config.local.php
 * (gitignored). Upload config.local.php to the server once, by hand,
 * through Hostinger's File Manager/FTP. It is NOT part of the auto-deploy.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$configPath = __DIR__ . '/config.local.php';
if (!file_exists($configPath)) {
    error_log('send-quote.php: config.local.php is missing.');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Email service is not configured yet.']);
    exit;
}
require $configPath;

if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '') {
    error_log('send-quote.php: RESEND_API_KEY is not set in config.local.php.');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Email service is not configured yet.']);
    exit;
}

const TO_EMAIL = 'mendelkat10@gmail.com';
const FROM_EMAIL = 'Prairie Window Washing <onboarding@resend.dev>';

const SERVICE_LABELS = [
    'windows' => 'Window Washing',
    'gutters' => 'Gutter Cleaning',
    'pressure' => 'Pressure Washing',
    'siding' => 'Siding & Soft Wash',
    'screens' => 'Screen & Track Cleaning',
    'solar' => 'Solar Panel Cleaning',
    'postconstruction' => 'Post-Construction Cleaning',
    'other' => 'Other / Not Sure',
];

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request body.']);
    exit;
}

$name = isset($body['name']) ? trim((string) $body['name']) : '';
$phone = isset($body['phone']) ? trim((string) $body['phone']) : '';
$email = isset($body['email']) ? trim((string) $body['email']) : '';
$service = isset($body['service']) ? trim((string) $body['service']) : '';
$message = isset($body['message']) ? trim((string) $body['message']) : '';
$consent = !empty($body['consent']);

if ($name === '' || $phone === '' || $email === '' || !$consent) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Please fill in your name, phone, email, and agree to be contacted.',
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}

$serviceLabel = SERVICE_LABELS[$service] ?? ($service !== '' ? $service : 'Not specified');

$html = '<h2>New Free Quote Request</h2>'
    . '<p>A new quote request came in from the Prairie Window Washing website.</p>'
    . '<table cellpadding="6" cellspacing="0" border="0">'
    . '<tr><td><strong>Name</strong></td><td>' . htmlspecialchars($name, ENT_QUOTES) . '</td></tr>'
    . '<tr><td><strong>Phone</strong></td><td>' . htmlspecialchars($phone, ENT_QUOTES) . '</td></tr>'
    . '<tr><td><strong>Email</strong></td><td>' . htmlspecialchars($email, ENT_QUOTES) . '</td></tr>'
    . '<tr><td><strong>Service</strong></td><td>' . htmlspecialchars($serviceLabel, ENT_QUOTES) . '</td></tr>'
    . '<tr><td valign="top"><strong>Message</strong></td><td>'
        . nl2br(htmlspecialchars($message !== '' ? $message : 'N/A', ENT_QUOTES))
    . '</td></tr>'
    . '</table>';

$payload = json_encode([
    'from' => FROM_EMAIL,
    'to' => [TO_EMAIL],
    'reply_to' => $email,
    'subject' => 'New Quote Request from ' . $name,
    'html' => $html,
]);

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . RESEND_API_KEY,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 15,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    error_log('send-quote.php: cURL error contacting Resend: ' . $curlError);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => "We couldn't send your request right now. Please call us instead."]);
    exit;
}

$data = json_decode($response, true);

if ($statusCode < 200 || $statusCode >= 300) {
    error_log('send-quote.php: Resend API error ' . $statusCode . ': ' . $response);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => "We couldn't send your request right now. Please call us instead."]);
    exit;
}

echo json_encode(['ok' => true, 'id' => $data['id'] ?? null]);
