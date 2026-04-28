<?php

/**
 * Snippe Webhook Callback
 *
 * Receives signed webhook events from Snippe and marks the matching WHMCS
 * invoice paid on payment.completed. Signature is verified against the raw
 * request body using HMAC-SHA256 with the merchant's webhook signing secret.
 *
 * Configure the signing secret in the gateway module settings before enabling.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'snippe';
$gateway = getGatewayVariables($gatewayModuleName);

if (!$gateway['type']) {
    http_response_code(503);
    exit('Module not activated');
}

$signingKey = isset($gateway['webhookSecret']) ? $gateway['webhookSecret'] : '';
if ($signingKey === '') {
    logTransaction($gatewayModuleName, ['error' => 'webhook secret not configured'], 'Failure');
    http_response_code(500);
    exit('Webhook secret not configured');
}

$rawBody = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = [];
foreach ($headers as $k => $v) {
    $headersLower[strtolower($k)] = $v;
}

$timestamp = $headersLower['x-webhook-timestamp'] ?? null;
$signature = $headersLower['x-webhook-signature'] ?? null;

if (!$timestamp || !$signature) {
    logTransaction($gatewayModuleName, ['error' => 'missing webhook headers', 'headers' => $headers], 'Failure');
    http_response_code(400);
    exit('Missing webhook headers');
}

if (time() - (int) $timestamp > 300) {
    logTransaction($gatewayModuleName, ['error' => 'timestamp too old', 'timestamp' => $timestamp], 'Failure');
    http_response_code(400);
    exit('Timestamp too old');
}

$expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $signingKey);
if (!hash_equals($expected, $signature)) {
    logTransaction($gatewayModuleName, ['error' => 'invalid signature', 'body' => $rawBody], 'Failure');
    http_response_code(400);
    exit('Invalid signature');
}

$event = json_decode($rawBody, true);
if (!is_array($event) || !isset($event['id'], $event['type'], $event['data'])) {
    http_response_code(400);
    exit('Malformed event');
}

$eventId = $event['id'];
$eventType = $event['type'];
$data = $event['data'];

if ($eventType !== 'payment.completed') {
    logTransaction($gatewayModuleName, $event, 'Ignored: ' . $eventType);
    http_response_code(200);
    exit('OK');
}

$invoiceId = $data['metadata']['invoice_id'] ?? null;
$reference = $data['reference'] ?? $eventId;
$paidAmount = isset($data['amount']['value']) ? (int) $data['amount']['value'] : 0;
$feeAmount = isset($data['settlement']['fees']['value']) ? (int) $data['settlement']['fees']['value'] : 0;

if (!$invoiceId) {
    logTransaction($gatewayModuleName, $event, 'Failure: missing invoice_id metadata');
    http_response_code(400);
    exit('Missing invoice_id');
}

$invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);
checkCbTransID($reference);

addInvoicePayment(
    $invoiceId,
    $reference,
    $paidAmount,
    $feeAmount,
    $gatewayModuleName
);

logTransaction($gatewayModuleName, $event, 'Success');

http_response_code(200);
echo 'OK';
