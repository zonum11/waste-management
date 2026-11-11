<?php
// Slows down password guessing by limiting failed logins per email and IP.
class LoginThrottle
{
    private const MAX_FAILURES   = 5;
    private const WINDOW_SECONDS = 900;
    private string $folder;
    private string $file;

    public function __construct(string $email, string $ip)
    {
        // Attempts live in files named by a hash, so no emails are stored on disk.
        $this->folder = dirname(__DIR__, 2) . '/storage/login-attempts';
        $this->file   = $this->folder . '/' . hash('sha256', strtolower($email) . '|' . $ip) . '.json';
    }

    // Seconds the user must still wait; 0 means they may try.
    public function secondsToWait(): int
    {
        $failures = $this->recentFailures($this->readFailures());
        if (count($failures) < self::MAX_FAILURES) {
            return 0;
        }
        return max(1, min($failures) + self::WINDOW_SECONDS - time());
    }

    public function recordFailure(): void
    {
        if (!is_dir($this->folder) && !@mkdir($this->folder, 0755, true) && !is_dir($this->folder)) {
            // Logins still work if the folder is missing; just log a warning.
            error_log('LoginThrottle: cannot create ' . $this->folder . ' - login attempts are NOT being limited.');
            return;
        }

        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            error_log('LoginThrottle: cannot write ' . $this->file . ' - login attempts are NOT being limited.');
            return;
        }

        // Lock the file so two requests at once do not overwrite each other.
        flock($handle, LOCK_EX);
        $failures   = $this->recentFailures($this->decode((string) stream_get_contents($handle)));
        $failures[] = time();
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($failures));
        flock($handle, LOCK_UN);
        fclose($handle);

        // Occasionally delete old attempt files.
        if (random_int(1, 50) === 1) {
            $this->removeOldFiles();
        }
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }

    private function readFailures(): array
    {
        $content = is_file($this->file) ? @file_get_contents($this->file) : false;
        return $this->decode($content === false ? '' : $content);
    }

    private function decode(string $json): array
    {
        $list = json_decode($json, true);
        return is_array($list) ? array_values(array_filter($list, 'is_int')) : [];
    }

    // Only failures inside the time window count.
    private function recentFailures(array $failures): array
    {
        $oldest = time() - self::WINDOW_SECONDS;
        return array_values(array_filter($failures, fn(int $time) => $time > $oldest));
    }

    private function removeOldFiles(): void
    {
        foreach (glob($this->folder . '/*.json') ?: [] as $path) {
            if (filemtime($path) < time() - self::WINDOW_SECONDS) {
                @unlink($path);
            }
        }
    }
}
