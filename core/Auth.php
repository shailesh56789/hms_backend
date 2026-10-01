<?php
require_once __DIR__ . '/JWT.php';
require_once __DIR__ . '/Response.php';

class Auth
{
    private static $currentUser = null;

    public static function getBearerToken()
    {
        $headers    = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = null;
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'authorization') { $authHeader = $value; break; }
        }
        if (!$authHeader && isset($_SERVER['HTTP_AUTHORIZATION'])) $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) return $matches[1];
        return null;
    }

    public static function authenticate()
    {
        $token = self::getBearerToken();
        if (!$token) Response::unauthorized("Missing authentication token");

        $payload = JWT::decode($token);
        if (!$payload) Response::unauthorized("Invalid or expired token");

        self::$currentUser = $payload;
        return $payload;
    }

    public static function user() { return self::$currentUser; }

    public static function authorize(array $allowedRoles)
    {
        $user = self::$currentUser ?? self::authenticate();
        if (!in_array($user['role'], $allowedRoles, true)) {
            Response::forbidden("You do not have permission to perform this action");
        }
        return $user;
    }
}
