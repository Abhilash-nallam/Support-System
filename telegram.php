<?php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';
function telegramApi(string $method,array $payload): array {
 global $config;
 if (empty($config['telegram_api_enabled'])) return ['ok'=>false,'description'=>'API disabled'];
 $url='https://api.telegram.org/bot'.$config['telegram_bot_token'].'/'.$method;
 $ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),
  CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,
  CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 $body=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
 if ($body===false) { error_log('Telegram cURL: '.$err); return ['ok'=>false,'description'=>'Network error: '.$err]; }
 $json=json_decode($body,true);
 if (!is_array($json)) return ['ok'=>false,'description'=>'Invalid Telegram response HTTP '.$code];
 if (empty($json['ok'])) error_log('Telegram API: '.($json['description']??'unknown'));
 return $json;
}
function telegramSend(string|int $chatId,string $text): array {
 return telegramApi('sendMessage',['chat_id'=>(string)$chatId,'text'=>$text,'disable_web_page_preview'=>'true']);
}
