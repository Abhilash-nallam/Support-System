<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function envRequired(string $key): string {
    $value = getenv($key);
    if ($value === false || trim($value) === '') {
        error_log('Missing required environment variable: ' . $key);
        respond(500, ['ok' => false, 'error' => 'Server configuration incomplete']);
    }
    return trim($value);
}

function ddb(): DynamoDbClient {
    static $client = null;
    if ($client instanceof DynamoDbClient) return $client;
    $client = new DynamoDbClient([
        'region' => getenv('AWS_REGION') ?: 'ap-south-1',
        'version' => 'latest'
    ]);
    return $client;
}

function marshaler(): Marshaler {
    static $m = null;
    if (!$m instanceof Marshaler) $m = new Marshaler();
    return $m;
}

function ticketsTable(): string { return envRequired('TICKETS_TABLE'); }
function repliesTable(): string { return envRequired('REPLIES_TABLE'); }

function cleanText(string $s, int $max = 3500): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

function telegramApi(string $method, array $payload): array {
    $token = envRequired('TELEGRAM_BOT_TOKEN');
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) {
        error_log('Telegram API network error: ' . $error);
        return ['ok' => false, 'description' => 'Network error'];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) return ['ok' => false, 'description' => 'Invalid Telegram response HTTP ' . $status];
    if (empty($data['ok'])) error_log('Telegram API error: ' . ($data['description'] ?? 'unknown'));
    return $data;
}

function sendText(string $chatId, string $text): array {
    return telegramApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'link_preview_options' => ['is_disabled' => true]
    ]);
}

function isAdmin(string $from, string $chatType): bool {
    return $chatType === 'private' && hash_equals(envRequired('ADMIN_TELEGRAM_ID'), $from);
}

function ticketCode(): string { return 'BR-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))); }

function getTicket(string $code): ?array {
    $result = ddb()->getItem([
        'TableName' => ticketsTable(),
        'Key' => ['ticket_code' => ['S' => $code]],
        'ConsistentRead' => true
    ]);
    return empty($result['Item']) ? null : marshaler()->unmarshalItem($result['Item']);
}

function addReply(string $code, string $sender, string $message): void {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    ddb()->putItem([
        'TableName' => repliesTable(),
        'Item' => marshaler()->marshalItem([
            'ticket_code' => $code,
            'reply_id' => $now . '#' . bin2hex(random_bytes(4)),
            'sender_type' => $sender,
            'message' => $message,
            'created_at' => $now
        ])
    ]);
}

function handleTelegramMessage(array $m): void {
    $chatId = (string)($m['chat']['id'] ?? '');
    $fromId = (string)($m['from']['id'] ?? '');
    $chatType = (string)($m['chat']['type'] ?? '');
    $text = trim((string)($m['text'] ?? ''));
    $user = $m['from'] ?? [];
    if ($chatId === '' || $fromId === '' || $chatType !== 'private' || $text === '') return;

    if (preg_match('/^\/start(?:@\w+)?(?:\s|$)/i', $text)) {
        if (!isAdmin($fromId, $chatType)) {
            $profile = getUserProfile($chatId);
            if ($profile) {
                sendText($chatId, "👋 Welcome to BitsResources Support!\n\nYour roll number: {$profile['roll_number']}\nSend your support issue as a message. Each request will be listed with its date and status.\n\nTo change your roll number, send /change_roll. Never send passwords or OTPs.");
            } else {
                sendText($chatId, "👋 Welcome to BitsResources Support!\n\nFirst, enter your BITSResources roll number (example: 24C31A66XX). You must enter it before sending a support issue.\n\nNever send passwords or OTPs.");
            }
        } else {
            sendText($chatId, "Admin commands:\n/tickets - latest tickets first\n/reply TICKET_ID your message\n/close TICKET_ID");
        }
        return;
    }
    if (preg_match('/^\/change_roll(?:@\w+)?$/i', $text) && !isAdmin($fromId, $chatType)) {
        ddb()->deleteItem(['TableName' => usersTable(), 'Key' => ['user_chat_id' => ['S' => $chatId]]]);
        sendText($chatId, "Please enter your roll number (example: 24C31A66XX) to continue.");
        return;
    }
    if (preg_match('/^\/help(?:@\w+)?$/i', $text)) {
        sendText($chatId, "BitsResources Support\n\nEnter your roll number first, then send your issue.\nEach request appears with date and status; newest tickets are listed first.\nDo not send passwords or OTPs.");
        return;
    }
    if (preg_match('/^\/tickets(?:@\w+)?$/i', $text)) {
        if (!isAdmin($fromId, $chatType)) { sendText($chatId, 'Admin only command.'); return; }
        $result = ddb()->scan(['TableName' => ticketsTable(), 'Limit' => 100]);
        $rows = array_map(fn($i) => marshaler()->unmarshalItem($i), $result['Items'] ?? []);
        usort($rows, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        $rows = array_slice($rows, 0, 20);
        if (!$rows) { sendText($chatId, 'No tickets yet.'); return; }
        $out = [];
        foreach ($rows as $r) {
            $out[] = "Roll: " . ($r['roll_number'] ?? 'Not recorded') . "\nDate: " . ($r['created_at'] ?? 'Unknown') . "\nStatus: " . strtoupper((string)($r['status'] ?? 'unknown')) . "\nTicket: " . $r['ticket_code'] . "\n" . cleanText((string)$r['message'], 180);
        }
        sendText($chatId, "🗂 LATEST SUPPORT TICKETS (newest first)\n\n" . implode("\n\n────────────\n\n", $out));
        return;
    }
    if (preg_match('/^\/reply(?:@\w+)?\s+(BR-\d{8}-[A-F0-9]{6})\s+([\s\S]+)$/i', $text, $x)) {
        if (!isAdmin($fromId, $chatType)) { sendText($chatId, 'Admin only command.'); return; }
        $code = strtoupper($x[1]);
        $reply = cleanText(trim($x[2]));
        $ticket = getTicket($code);
        if (!$ticket) { sendText($chatId, 'Ticket not found.'); return; }
        $sent = sendText((string)$ticket['user_chat_id'], "💬 Reply from BitsResources Support\n\nTicket: {$code}\n\n{$reply}");
        if (empty($sent['ok'])) { sendText($chatId, '❌ Reply failed. The user may not have started the bot or may have blocked it.'); return; }
        addReply($code, 'admin', $reply);
        ddb()->updateItem([
            'TableName' => ticketsTable(),
            'Key' => ['ticket_code' => ['S' => $code]],
            'UpdateExpression' => 'SET replied_at = :time',
            'ExpressionAttributeValues' => [':time' => ['S' => gmdate('Y-m-d\TH:i:s\Z')]]
        ]);
        sendText($chatId, "✅ Reply sent to {$code}.");
        return;
    }
    if (preg_match('/^\/close(?:@\w+)?\s+(BR-\d{8}-[A-F0-9]{6})$/i', $text, $x)) {
        if (!isAdmin($fromId, $chatType)) { sendText($chatId, 'Admin only command.'); return; }
        $code = strtoupper($x[1]);
        $ticket = getTicket($code);
        if (!$ticket || ($ticket['status'] ?? '') !== 'open') { sendText($chatId, 'Open ticket not found.'); return; }
        ddb()->updateItem([
            'TableName' => ticketsTable(),
            'Key' => ['ticket_code' => ['S' => $code]],
            'UpdateExpression' => 'SET #st = :closed, closed_at = :time',
            'ExpressionAttributeNames' => ['#st' => 'status'],
            'ExpressionAttributeValues' => [':closed' => ['S' => 'closed'], ':time' => ['S' => gmdate('Y-m-d\TH:i:s\Z')]]
        ]);
        sendText($chatId, '✅ Ticket closed.');
        return;
    }
    if (preg_match('/^\/status(?:@\w+)?\s+(BR-\d{8}-[A-F0-9]{6})$/i', $text, $x)) {
        $code = strtoupper($x[1]);
        $ticket = getTicket($code);
        if (!$ticket || (string)$ticket['user_chat_id'] !== $chatId) { sendText($chatId, 'Ticket not found for your Telegram account.'); return; }
        sendText($chatId, "Ticket: {$ticket['ticket_code']}\nStatus: {$ticket['status']}\nCreated: {$ticket['created_at']} UTC\nLast reply: " . ($ticket['replied_at'] ?? 'Waiting for admin reply'));
        return;
    }
    if (isAdmin($fromId, $chatType)) {
        sendText($chatId, "Admin commands:\n/tickets - latest tickets first with roll, date and status\n/reply TICKET_ID your message\n/close TICKET_ID");
        return;
    }

    $profile = getUserProfile($chatId);
    if (!$profile) {
        $roll = strtoupper(preg_replace('/\\s+/', '', $text) ?? '');
        if (!preg_match('/^[A-Z0-9]{8,12}$/', $roll)) {
            sendText($chatId, "Please enter a valid roll number using 8–12 letters/numbers (example: 24C31A66XX).");
            return;
        }
        saveUserProfile($chatId, $roll, $user);
        sendText($chatId, "✅ Roll number saved: {$roll}\n\nNow send your support issue. Your requests will be recorded under this roll number with date and status.");
        return;
    }

    $body = cleanText($text);
    $name = trim(cleanText((string)($user['first_name'] ?? ''), 80) . ' ' . cleanText((string)($user['last_name'] ?? ''), 80));
    $username = isset($user['username']) ? cleanText((string)$user['username'], 80) : '';
    $roll = (string)$profile['roll_number'];
    $code = ticketCode();
    $now = gmdate('Y-m-d\TH:i:s\Z');
    ddb()->putItem([
        'TableName' => ticketsTable(),
        'Item' => marshaler()->marshalItem([
            'ticket_code' => $code,
            'user_chat_id' => $chatId,
            'user_name' => $name,
            'username' => $username,
            'roll_number' => $roll,
            'message' => $body,
            'status' => 'open',
            'created_at' => $now
        ]),
        'ConditionExpression' => 'attribute_not_exists(ticket_code)'
    ]);
    addReply($code, 'user', $body);
    $adminText = "📩 NEW BITSRESOURCES SUPPORT TICKET\n\nRoll number: {$roll}\nDate: {$now}\nStatus: OPEN\nTicket reference: {$code}\nFrom: " . ($name ?: 'Telegram user') . "\nUsername: " . ($username !== '' ? '@' . $username : '(none)') . "\n\nMessage:\n{$body}\n\nReply:\n/reply {$code} Your message here\n\nClose:\n/close {$code}";
    $adminResult = sendText(envRequired('ADMIN_TELEGRAM_ID'), $adminText);
    sendText($chatId, "✅ Your support request has been received!\n\nRoll number: {$roll}\nDate: {$now}\nStatus: OPEN\nReference: {$code}\n\n" . (empty($adminResult['ok']) ? 'Your request was saved, but admin notification may be delayed.' : 'The admin has been notified.') . "\n\nYou'll receive a reply here.\n/status {$code}");
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($method === 'GET') respond(200, ['ok' => true, 'service' => 'Telegram Support Bot on AWS', 'path' => $path]);
if ($method !== 'POST') respond(405, ['ok' => false, 'error' => 'Method not allowed']);

$expected = envRequired('TELEGRAM_WEBHOOK_SECRET');
$provided = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($provided === '' || !hash_equals($expected, $provided)) respond(403, ['ok' => false, 'error' => 'Forbidden']);

$update = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($update)) respond(400, ['ok' => false, 'error' => 'Invalid JSON']);
try {
    if (isset($update['message']) && is_array($update['message'])) handleTelegramMessage($update['message']);
    respond(200, ['ok' => true]);
} catch (Throwable $e) {
    error_log('Support webhook exception: ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'Internal error']);
}
