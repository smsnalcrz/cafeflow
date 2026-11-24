<?php
declare(strict_types=1);

// Discard oversized or unrecognizable fields. Keep valid sibling fields and the event itself.
function audit_clean_fields(array $fields, int $depth, int &$remaining): array
{
    $clean = [];
    foreach ($fields as $key => $value) {
        if ($remaining-- <= 0) break;
        if (is_string($key)) {
            if (!preg_match('/^[a-z0-9_ .-]{1,64}$/iD', $key)) continue;
            $name = strtolower(str_replace(['_', ' ', '.', '-'], '', $key));
            $sensitive = '/password|passwd|passphrase|secret|credential|token|cookie|csrf|otp|'
                . 'recoverycode|backupcode|sessionid|phpsessid|cafeflowsession|authorization|bearer|apikey|privatekey|'
                . 'verificationcode|securitycode|cardnumber|creditcard|cardholder|bankaccount|paymentref|'
                . 'referencenumber|discountid|email|phone|fullname|address|(^|new|old)(pin|pwd)$|'
                . '^(pan|cvv|cvc|2facode|mfacode|twofactorcode|code|passwordhash)$/';
            if (preg_match($sensitive, $name)) continue;
        }
        if ($value instanceof stdClass) $value = get_object_vars($value);
        if (is_array($value)) {
            if ($depth < 8) $clean[$key] = audit_clean_fields($value, $depth + 1, $remaining);
        } elseif (is_string($value)) {
            if (strlen($value) <= 1024 && preg_match('//u', $value)
                && !preg_match('/^\s*[\[{]/', $value)) {
                $clean[$key] = $value;
            }
        } elseif ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            $clean[$key] = $value;
        }
    }
    return $clean;
}

function audit_metadata_json(?array $fields): ?string
{
    if (!$fields) return null;
    $remaining = 128;
    $clean = audit_clean_fields($fields, 0, $remaining);
    $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $clean && $json !== false && strlen($json) <= 8192 ? $json : null;
}

function audit_stored_metadata(?string $json): ?string
{
    if ($json === null || strlen($json) > 65536) return null;
    $fields = json_decode($json, true, 32);
    return is_array($fields) ? audit_metadata_json($fields) : null;
}
