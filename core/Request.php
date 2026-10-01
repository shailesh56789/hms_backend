<?php
class Request
{
    private static $body = null;

    public static function body()
    {
        if (self::$body === null) {
            $raw = file_get_contents("php://input");
            $decoded = json_decode($raw, true);
            self::$body = is_array($decoded) ? $decoded : [];
        }
        return self::$body;
    }

    public static function input($key, $default = null)
    {
        $body = self::body();
        return isset($body[$key]) ? $body[$key] : $default;
    }

    public static function query($key, $default = null)
    {
        return isset($_GET[$key]) && $_GET[$key] !== '' ? $_GET[$key] : $default;
    }

    public static function pagination()
    {
        $page    = max(1, (int) self::query('page', 1));
        $perPage = (int) self::query('per_page', 10);
        $perPage = ($perPage > 0 && $perPage <= 1000) ? $perPage : 10;
        $offset  = ($page - 1) * $perPage;
        return ['page' => $page, 'per_page' => $perPage, 'offset' => $offset];
    }

    public static function paginated($totalCount, $page, $perPage)
    {
        return [
            'total'       => (int) $totalCount,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($totalCount / $perPage),
        ];
    }

    public static function validateRequired(array $keys)
    {
        $errors = [];
        $body   = self::body();
        foreach ($keys as $key) {
            if (!isset($body[$key]) || (is_string($body[$key]) && trim($body[$key]) === '')) {
                $errors[$key] = "$key is required";
            }
        }
        return $errors;
    }
}
