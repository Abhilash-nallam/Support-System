<?php
declare(strict_types=1);
require __DIR__.'/db.php'; require __DIR__.'/telegram.php';
$config=require __DIR__.'/config.php';
header('Content-Type: application/json; charset=utf-8');
$expected=(string)$config['webhook_secret'];
$provided=(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'');
if ($expected==='' || !hash_equals($expected,$provided)) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }
$update=json_decode(file_get_contents('php://input')?:'',true);
if (!is_array($update)) { http_response_code(400); echo json_encode(['ok'=>false]); exit; }
$m=$update['message']??null;
if (!$m || empty($m['chat']['id']) || empty($m['from']['id'])) { echo json_encode(['ok'=>true]); exit; }
$chat=(string)$m['chat']['id']; $from=(string)$m['from']['id']; $type=(string)($m['chat']['type']??''); $text=trim((string)($m['text']??'')); $u=$m['from'];
function sendText(string $chat,string $text): void { $r=telegramSend($chat,$text); if(empty($r['ok'])) error_log('Send failed: '.($r['description']??'')); }
function adminPrivate(string $from,string $type): bool { global $config; return $type==='private' && hash_equals((string)$config['admin_telegram_id'],$from); }
function cleanText(string $s,int $max=3500): string { $s=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',$s)??''; return function_exists('mb_substr')?mb_substr($s,0,$max,'UTF-8'):substr($s,0,$max); }
function code(): string { return 'BR-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3))); }
try {
 if ($type!=='private' || $text==='') { echo json_encode(['ok'=>true]); exit; }
 if (preg_match('/^\/start(?:@\w+)?(?:\s|$)/i',$text)) {
  sendText($chat,"👋 Welcome to BitsResources Support!\n\nSend your question here. I'll create a ticket and notify the admin. You'll receive the reply in this chat.\n\nNever send passwords or OTPs.");
  echo json_encode(['ok'=>true]); exit;
 }
 if (preg_match('/^\/help(?:@\w+)?$/i',$text)) {
  sendText($chat,"BitsResources Support\n\nSend your issue as a message to create a ticket.\nUse /status BR-YYYYMMDD-ABC123 to check it.\nDo not send passwords or OTPs.");
  echo json_encode(['ok'=>true]); exit;
 }
 if (preg_match('/^\/tickets(?:@\w+)?$/i',$text)) {
  if (!adminPrivate($from,$type)) sendText($chat,'Admin only command.');
  else {
   $rows=db()->query("SELECT ticket_code,user_name,message FROM support_tickets WHERE status='open' ORDER BY created_at ASC LIMIT 20")->fetchAll();
   if (!$rows) sendText($chat,'✅ No open tickets.');
   else { $out=[]; foreach($rows as $r) $out[]=$r['ticket_code'].' · '.($r['user_name']?:'User')."\n".cleanText($r['message'],180); sendText($chat,"🗂 OPEN TICKETS\n\n".implode("\n\n",$out)); }
  }
  echo json_encode(['ok'=>true]); exit;
 }
 if (preg_match('/^\/reply(?:@\w+)?\s+(BR-\d{8}-[A-F0-9]{6})\s+([\s\S]+)$/i',$text,$x)) {
  if (!adminPrivate($from,$type)) { sendText($chat,'Admin only command.'); echo json_encode(['ok'=>true]); exit; }
  $code=strtoupper($x[1]); $reply=cleanText(trim($x[2]));
  $q=db()->prepare('SELECT * FROM support_tickets WHERE ticket_code=? LIMIT 1'); $q->execute([$code]); $t=$q->fetch();
  if (!$t) sendText($chat,'Ticket not found.');
  else {
   $sent=telegramSend((string)$t['user_chat_id'],"💬 Reply from BitsResources Support\n\nTicket: {$code}\n\n{$reply}");
   if (empty($sent['ok'])) sendText($chat,'❌ Reply failed. User may not have started the bot or may have blocked it.');
   else { $pdo=db(); $pdo->beginTransaction(); $q=$pdo->prepare("INSERT INTO support_replies(ticket_id,sender_type,message) VALUES(?,'admin',?)"); $q->execute([$t['id'],$reply]); $q=$pdo->prepare('UPDATE support_tickets SET replied_at=NOW() WHERE id=?'); $q->execute([$t['id']]); $pdo->commit(); sendText($chat,"✅ Reply sent to {$code}."); }
  }
  echo json_encode(['ok'=>true]); exit;
 }
 if (preg_match('/^\/close(?:@\w+)?\s+(BR-\d{8}-[A-F0-9]{6})$/i',$text,$x)) {
  if (!adminPrivate($from,$type)) sendText($chat,'Admin only command.');
  else { $q=db()->prepare("UPDATE support_tickets SET status='closed',closed_at=NOW() WHERE ticket_code=? AND status='open'"); $q->execute([strtoupper($x[1])]); sendText($chat,$q->rowCount()?'✅ Ticket closed.':'Open ticket not found.'); }
  echo json_encode(['ok'=>true]); exit;
 }
 if (preg_match('/^\/status(?:@\w+)?\s+(BR-\d{8}-[A-F0-9]{6})$/i',$text,$x)) {
  $q=db()->prepare('SELECT ticket_code,status,created_at,replied_at FROM support_tickets WHERE ticket_code=? AND user_chat_id=? LIMIT 1'); $q->execute([strtoupper($x[1]),$chat]); $t=$q->fetch();
  if (!$t) sendText($chat,'Ticket not found for your Telegram account.');
  else sendText($chat,"Ticket: {$t['ticket_code']}\nStatus: {$t['status']}\nCreated: {$t['created_at']} UTC\nLast reply: ".($t['replied_at']?:'Waiting for admin reply'));
  echo json_encode(['ok'=>true]); exit;
 }
 if (adminPrivate($from,$type)) { sendText($chat,"Admin commands:\n/tickets\n/reply TICKET_ID your message\n/close TICKET_ID\n\nTest user flow from another Telegram account."); echo json_encode(['ok'=>true]); exit; }
 $body=cleanText($text); $ticketCode=code(); $name=trim(cleanText((string)($u['first_name']??''),80).' '.cleanText((string)($u['last_name']??''),80)); $username=isset($u['username'])?cleanText((string)$u['username'],80):null;
 $pdo=db(); $q=$pdo->prepare('INSERT INTO support_tickets(ticket_code,user_chat_id,user_name,username,message) VALUES(?,?,?,?,?)'); $q->execute([$ticketCode,$chat,$name,$username,$body]); $id=(int)$pdo->lastInsertId();
 $q=$pdo->prepare("INSERT INTO support_replies(ticket_id,sender_type,message) VALUES(?,'user',?)"); $q->execute([$id,$body]);
 $adminText="📩 NEW BITSRESOURCES SUPPORT TICKET\n\nTicket: {$ticketCode}\nFrom: ".($name?:'Telegram user')."\nUsername: ".($username?'@'.$username:'(none)')."\nUser ID: {$chat}\n\nMessage:\n{$body}\n\nReply:\n/reply {$ticketCode} Your message here\n\nClose:\n/close {$ticketCode}";
 $a=telegramSend((string)$config['admin_telegram_id'],$adminText);
 sendText($chat,"✅ Your support request has been received!\n\nTicket ID: {$ticketCode}\n\n".(empty($a['ok'])?'Your request was saved, but admin notification may be delayed.':'The admin has been notified.')."\n\nYou'll receive a reply here.\n/status {$ticketCode}");
 echo json_encode(['ok'=>true]);
} catch(Throwable $e) { error_log('Support webhook error: '.$e->getMessage()); echo json_encode(['ok'=>true,'handled_with_error'=>true]); }
