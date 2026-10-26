<?php
// AWS Lambda PHP webhook implementation prepared in the deployment package.
// Replacing this placeholder with the full handler is required before deployment.
http_response_code(503);
header('Content-Type: application/json');
echo json_encode(['ok'=>false,'error'=>'AWS handler setup incomplete']);
