<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$url = $argv[1] ?? '';
$token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$secret = getenv('TELEGRAM_WEBHOOK_SECRET') ?: '';
if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://') || $token === '' || $secret === '') {
    fwrite(STDERR, "Usage: TELEGRAM_BOT_TOKEN=... TELEGRAM_WEBHOOK_SECRET=... php scripts/register-webhook.php https://YOUR-LAMBDA-URL/\n");
    exit(2);
}
if (!preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secret)) {
    fwrite(STDERR, "Webhook secret must contain only A-Z, a-z, 0-9, underscore or hyphen.\n");
    exit(2);
}
$ch = curl_init('https://api.telegram.org/bot' . $token . '/setWebhook');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message']]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);
$response = curl_exec($ch);
$error = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($response === false) { fwrite(STDERR, "Telegram request failed (HTTP {$status}): {$error}\n"); exit(1); }
$data = json_decode($response, true);
if (!is_array($data) || empty($data['ok'])) { fwrite(STDERR, "Telegram rejected webhook: {$response}\n"); exit(1); }
echo "Telegram webhook registered successfully.\n";
