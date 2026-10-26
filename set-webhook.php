<?php
// One-time setup only. Change setup key, visit once, then DELETE this file.
$setup_key='CHANGE_THIS_SETUP_KEY';
if (!isset($_GET['key']) || !hash_equals($setup_key,(string)$_GET['key'])) { http_response_code(403); exit('Forbidden'); }
$c=require __DIR__.'/config.php';
$dir=rtrim(dirname($_SERVER['SCRIPT_NAME']),'/');
$url='https://'.$_SERVER['HTTP_HOST'].($dir ? $dir : '').'/webhook.php';
$payload=['url'=>$url,'secret_token'=>$c['webhook_secret'],'allowed_updates'=>json_encode(['message'])];
$ch=curl_init('https://api.telegram.org/bot'.$c['telegram_bot_token'].'/setWebhook');
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
$out=curl_exec($ch); $err=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
header('Content-Type: text/plain; charset=utf-8');
echo $out===false ? "cURL failed: {$err} (HTTP {$status})" : "Webhook URL: {$url}\nTelegram response:\n{$out}\n\nDelete set-webhook.php now if successful.";
