<?php
// Shared helpers for the API tests: a small HTTP client and check functions.

/** Small HTTP client that keeps cookies, like a browser tab. */
class Client
{
    private string $cookieFile;
    private string $baseUrl;
    public ?string $csrf = null;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl    = $baseUrl;
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'cookie');
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }

    /** Returns [status, json]. */
    public function request(string $method, string $path, ?array $body = null, bool $sendCsrf = true): array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($method !== 'GET' && $sendCsrf && $this->csrf !== null) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }

        $ch = curl_init("{$this->baseUrl}/$path");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_TIMEOUT        => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, json_decode((string) $raw, true)];
    }

    /** Sends a multipart form, optionally with a "photo" file. Returns [status, json]. */
    public function postForm(string $path, array $fields, ?string $photoPath = null, string $photoName = 'photo.png'): array
    {
        $post = [];
        foreach ($fields as $name => $value) {
            if (is_array($value)) {
                foreach (array_values($value) as $i => $item) {
                    $post["{$name}[$i]"] = $item;
                }
            } else {
                $post[$name] = $value;
            }
        }
        if ($photoPath !== null) {
            $post['photo'] = new CURLFile($photoPath, mime_content_type($photoPath) ?: 'application/octet-stream', $photoName);
        }

        // No Content-Type here: curl adds it, including the multipart boundary.
        $headers = ['Accept: application/json'];
        if ($this->csrf !== null) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }

        $ch = curl_init("{$this->baseUrl}/$path");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, json_decode((string) $raw, true)];
    }

    /** GET for raw replies such as images. Returns [status, type, body, headers]. */
    public function getRaw(string $path): array
    {
        $ch = curl_init("{$this->baseUrl}/$path");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $reply      = (string) curl_exec($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type       = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [$status, $type, substr($reply, $headerSize), substr($reply, 0, $headerSize)];
    }

    /** Sends the body text exactly as given, even broken JSON. */
    public function sendRaw(string $method, string $path, string $rawBody, string $contentType = 'application/json'): array
    {
        $headers = ['Accept: application/json', "Content-Type: $contentType"];
        if ($this->csrf !== null) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }

        $ch = curl_init("{$this->baseUrl}/$path");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $rawBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $reply      = (string) curl_exec($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [$status, json_decode(substr($reply, $headerSize), true), substr($reply, 0, $headerSize)];
    }

    /** The session cookie value, or null. */
    public function sessionId(): ?string
    {
        foreach (file($this->cookieFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $parts = explode("\t", $line);
            if (count($parts) >= 7 && $parts[5] === 'WASTESESSID') {
                return $parts[6];
            }
        }
        return null;
    }

    public function fetchCsrf(): void
    {
        [, $json] = $this->request('GET', 'auth/csrf');
        $this->csrf = $json['data']['csrf_token'] ?? null;
    }

    /** Logs in and saves the new CSRF token. Returns the status. */
    public function login(string $email, string $password): int
    {
        $this->fetchCsrf();
        [$status, $json] = $this->request('POST', 'auth/login', ['email' => $email, 'password' => $password]);
        $this->csrf = $json['data']['csrf_token'] ?? $this->csrf;
        return $status;
    }
}

$passed = 0;
$failed = 0;

function check(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name\n";
    }
}

/** Prints the totals and exits with 0 (all passed) or 1. */
function finishTests(): void
{
    global $passed, $failed;
    echo "\n$passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
}

/** Plain request with no cookies or login. Returns [status, headers, body]. */
function rawHttp(string $url, string $method = 'GET', array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $reply      = (string) curl_exec($ch);
    $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [$status, substr($reply, 0, $headerSize), substr($reply, $headerSize)];
}

/** True if the headers contain this header (and the value text, if given). */
function hasHeader(string $headerText, string $name, string $valueContains = ''): bool
{
    foreach (explode("\n", $headerText) as $line) {
        if (stripos($line, "$name:") === 0 && ($valueContains === '' || stripos($line, $valueContains) !== false)) {
            return true;
        }
    }
    return false;
}
