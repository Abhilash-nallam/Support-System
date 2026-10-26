<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'service'=>'BitsResources Telegram Support PHP','time_utc'=>gmdate('c')]);
