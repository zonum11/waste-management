<?php
// Sends plain-text email with PHP's built-in mail() (works on most cPanel hosts).
class Mailer
{
    // Returns true when the server accepted the message.
    public static function send(string $to, string $subject, string $body): bool
    {
        // Header values must never contain line breaks (header injection).
        if (preg_match('/[\r\n]/', $to . $subject)) {
            return false;
        }

        $config = loadConfig();
        $from   = $config['mail_from'] ?? ('no-reply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost'));
        $from   = preg_replace('/[\r\n]/', '', (string) $from);
        $host   = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = 'no-reply@' . $host;
        }

        $headers = "From: Barangay Waste Management <$from>\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n";
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    }
}
