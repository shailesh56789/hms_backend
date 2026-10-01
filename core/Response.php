<?php
class Response
{
    public static function json($status, $message, $data = null, $httpCode = 200)
    {
        http_response_code($httpCode);
        echo json_encode(["status" => $status, "message" => $message, "data" => $data]);
        exit;
    }
    public static function success($message = "Success", $data = null, $httpCode = 200) { self::json(true, $message, $data, $httpCode); }
    public static function error($message = "Something went wrong", $httpCode = 400, $data = null) { self::json(false, $message, $data, $httpCode); }
    public static function unauthorized($message = "Unauthorized") { self::json(false, $message, null, 401); }
    public static function forbidden($message = "Forbidden") { self::json(false, $message, null, 403); }
    public static function notFound($message = "Not found") { self::json(false, $message, null, 404); }
    public static function validationError($errors) { self::json(false, "Validation failed", ["errors" => $errors], 422); }
}
