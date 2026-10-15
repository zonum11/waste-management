<?php
// Sends JSON replies that always have the same shape.
class Response
{
    public static function success(string $message, array $data = [], int $status = 200): void
    {
        self::send($status, ['success' => true, 'message' => $message, 'data' => $data]);
    }

    public static function created(string $message, array $data = []): void
    {
        self::send(201, ['success' => true, 'message' => $message, 'data' => $data]);
    }

    // $errors maps a field name to its message.
    public static function error(string $message, int $status = 400, array $errors = []): void
    {
        $payload = ['success' => false, 'message' => $message];
        if ($errors) {
            $payload['errors'] = $errors;
        }
        self::send($status, $payload);
    }

    private static function send(int $status, array $payload): void
    {
        // Bad UTF-8 would make json_encode fail, so replace those bytes instead.
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $status = 500;
            $json   = '{"success":false,"message":"Something went wrong. Please try again later."}';
        }

        http_response_code($status);
        echo $json;
        exit;    }
}
