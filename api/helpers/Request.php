<?php
// Reads the data the browser sent with the request.
class Request
{
    // Biggest JSON body we accept (64 KB); our forms are much smaller.
    private const MAX_JSON_BYTES = 65536;

    public static function jsonBody(): array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_JSON_BYTES) {
            Response::error('The request is too large.', 413);
        }

        // Read the body with a cap, so a sender who hides its size cannot flood us.
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES + 1);
        if ($raw === false || $raw === '') {
            return [];
        }
        if (strlen($raw) > self::MAX_JSON_BYTES) {
            Response::error('The request is too large.', 413);
        }

        $data = json_decode($raw, true);        if (!is_array($data)) {
            Response::error('Request body must be valid JSON.', 400);
        }
        return $data;
    }

    // Reads a multipart form (used when a photo is attached).
    public static function formData(): array
    {
        // An upload over the server limit arrives empty, so detect it here.
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST && !$_FILES) {
            Response::error('The upload is too large. Choose a smaller photo.', 413);
        }

        // Refuse invalid UTF-8 text, which can break later steps.
        $valid = true;
        array_walk_recursive($_POST, function ($value) use (&$valid): void {
            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                $valid = false;
            }
        });
        if (!$valid) {
            Response::error('The form contains characters that are not valid text.', 400);
        }

        return $_POST;
    }
}
