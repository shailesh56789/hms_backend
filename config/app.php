<?php
/**
 * Application-level configuration
 */
define('JWT_SECRET', 'hms-google-sheets-jwt-secret-2026');
define('JWT_ALGO', 'HS256');
define('JWT_EXPIRY_SECONDS', 60 * 60 * 24); // 24 hours

define('ROLES', ['doctor', 'staff']);

// ✅ Apni Google Sheet ka ID yahan set karo
define('GOOGLE_SHEET_ID', '1mDp_VNDl6N9RJafFQc_k9_6VCOHzuEjy6CzXS4mpgMk');

// Service Account JSON key file ka path
define('GOOGLE_SERVICE_ACCOUNT_JSON', __DIR__ . '/google-credentials.json');

function apply_cors_headers()
{
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Content-Type: application/json; charset=UTF-8");

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}
