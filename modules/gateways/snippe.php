<?php

# Snippe Payment Gateway

/**
 * WHMCS Snippe Payment Gateway Module
 *
 * Integrates Snippe (api.snippe.sh) hosted checkout sessions with WHMCS.
 * Flow: snippe_link() renders a "Pay with Snippe" button, the in-file POST
 * handler creates a checkout session via Snippe's API and redirects the
 * customer to the hosted checkout URL. On completion Snippe redirects back
 * to WHMCS and posts a signed webhook to modules/gateways/callback/snippe.php
 * which marks the invoice paid.
 *
 * @see https://developers.whmcs.com/payment-gateways/
 * @see https://snippe.sh/docs/2026-01-25
 */
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

if (isset($_POST['snippe_model']) && $_POST['snippe_model'] != '') {
    $payload = base64_decode($_POST['snippe_model']);
    $apiKey = $_POST['snippe_api_key'];

    $url = 'https://api.snippe.sh/api/v1/sessions';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ));
    $result = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    logModuleCall('snippe', 'create-session', $payload, $result);

    $decoded = json_decode($result, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['data']['checkout_url'])) {
        header('Location: ' . $decoded['data']['checkout_url']);
        exit;
    }

    $errorMsg = isset($decoded['message']) ? $decoded['message'] : 'Unable to create Snippe checkout session';
    echo '<p style="font-family:Arial,sans-serif;color:#b00;">' . htmlspecialchars($errorMsg) . '</p>';
    echo '<p><a href="javascript:history.back()">Go back</a></p>';
    exit;
}

function snippe_MetaData() {
    return array(
        'DisplayName' => 'Snippe Payment Gateway',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function snippe_config() {
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Snippe Payment Gateway',
        ),
        'apiKey' => array(
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Enter your Snippe API key (starts with snp_). Get one from the Snippe Dashboard → Settings → API Keys.',
        ),
        'webhookSecret' => array(
            'FriendlyName' => 'Webhook Signing Secret',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Snippe webhook signing secret (Dashboard → Settings → Webhook Secret). Required for the callback handler to verify events.',
        ),
        'profileId' => array(
            'FriendlyName' => 'Payment Profile ID',
            'Type' => 'text',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Optional. Snippe payment profile ID for consistent branding (prof_...).',
        ),
        'allowedMethods' => array(
            'FriendlyName' => 'Allowed Methods',
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'mobile_money,card,qr',
            'Description' => 'Comma-separated list. Supported values: mobile_money, card, qr.',
        ),
        'expiresIn' => array(
            'FriendlyName' => 'Session Expiry (seconds)',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '3600',
            'Description' => 'How long the checkout session stays valid. Default 3600 (1 hour).',
        ),
    );
}

function snippe_link($params) {
    $apiKey = $params['apiKey'];
    $profileId = trim($params['profileId']);
    $allowedMethodsRaw = $params['allowedMethods'] ? $params['allowedMethods'] : 'mobile_money,card,qr';
    $expiresIn = (int) $params['expiresIn'];
    if ($expiresIn <= 0) {
        $expiresIn = 3600;
    }

    $systemurl = rtrim($params['systemurl'], '/') . '/';
    $successUrl = $systemurl . 'viewinvoice.php?id=' . $params['invoiceid'] . '&paymentsuccess=true';
    $webhookUrl = $systemurl . 'modules/gateways/callback/snippe.php';

    $invoiceId = $params['invoiceid'];
    $amount = (int) round($params['amount']);
    $description = $params['description'];

    $firstname = $params['clientdetails']['firstname'];
    $lastname = $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $phone = $params['clientdetails']['phonenumber'];

    $allowedMethods = array_values(array_filter(array_map('trim', explode(',', $allowedMethodsRaw))));

    $postfields = array(
        'amount' => $amount,
        'currency' => 'TZS',
        'description' => $description . ' (Invoice #' . $invoiceId . ')',
        'customer' => array(
            'name' => trim($firstname . ' ' . $lastname),
            'email' => $email,
            'phone' => $phone,
        ),
        'redirect_url' => $successUrl,
        'webhook_url' => $webhookUrl,
        'metadata' => array(
            'invoice_id' => (string) $invoiceId,
            'source' => 'WHMCS',
        ),
        'expires_in' => $expiresIn,
    );

    if (!empty($allowedMethods)) {
        $postfields['allowed_methods'] = $allowedMethods;
    }
    if ($profileId !== '') {
        $postfields['profile_id'] = $profileId;
    }

    logModuleCall('snippe', 'create-link', $postfields, []);

    $encoded = base64_encode(json_encode($postfields));

    $htmlOutput = '<form method="post" id="snippe_form_to_submit">';
    $htmlOutput .= '<textarea name="snippe_model" rows="4" cols="50" style="display:none;">' . $encoded . '</textarea>';
    $htmlOutput .= '<input type="hidden" name="snippe_api_key" value="' . htmlspecialchars($apiKey) . '" />';
    $htmlOutput .= '<button type="submit" style="padding:12px 24px;background:#0d6efd;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:bold;cursor:pointer;">Pay with Snippe</button>';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}
