<?php
// Handles the photos of a waste report (1 required, up to 2), with strict upload checks.
class PhotoUpload
{
    public const MAX_PHOTOS = 2;
    private const MAX_TOTAL_BYTES = 15 * 1024 * 1024;   // for all photos of one report together
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)$/';

    public static function hasFile(?array $file): bool
    {
        return $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    // Turns $_FILES['photos'] into a plain list with one entry per chosen file (empty slots are dropped).
    public static function collect(?array $files): array
    {
        if ($files === null) {
            return [];
        }
        if (!is_array($files['error'] ?? null)) {
            return self::hasFile($files) ? [$files] : [];
        }
        $list = [];
        foreach (array_keys($files['error']) as $i) {
            $one = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $one[$key] = $files[$key][$i] ?? null;
            }
            if (self::hasFile($one)) {
                $list[] = $one;
            }
        }
        return $list;
    }

    // Checks the whole set: at least 1 photo, at most 2, each a real image, 15 MB in total.
    // Returns an error message, or null when everything is fine.
    public static function validateAll(array $files): ?string
    {
        if (count($files) === 0) {
            return 'Please add at least 1 photo.';
        }
        if (count($files) > self::MAX_PHOTOS) {
            return 'You can upload up to ' . self::MAX_PHOTOS . ' photos.';
        }
        $total = 0;
        foreach ($files as $file) {
            $error = self::validate($file);
            if ($error !== null) {
                return $error;
            }
            $total += (int) $file['size'];
        }
        if ($total > self::MAX_TOTAL_BYTES) {
            return 'Your photos must be 15 MB or smaller in total.';
        }
        return null;
    }

    // Checks one photo. Returns an error message, or null when it is fine or missing.
    public static function validate(?array $file): ?string
    {
        if (!self::hasFile($file)) {
            return null;
        }
        if (is_array($file['error'])) {
            return 'Upload each photo as a separate file.';
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'Your photos must be 15 MB or smaller in total.';
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return 'A photo could not be uploaded. Please try again.';
        }
        if ($file['size'] > self::MAX_TOTAL_BYTES) {
            return 'Your photos must be 15 MB or smaller in total.';
        }

        // Check the real file contents, not the name or type the browser claims.
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || !isset(self::TYPES[$info['mime']])) {
            return 'Photos must be JPEG, PNG or WebP images.';
        }
        return null;
    }

    public static function save(array $file): string
    {
        $mime = getimagesize($file['tmp_name'])['mime'];
        // Random server-chosen name, never the user's file name.
        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];

        $folder = self::folder();
        if (!is_dir($folder) && !mkdir($folder, 0755, true)) {
            throw new RuntimeException('Could not create the uploads folder.');
        }
        if (!move_uploaded_file($file['tmp_name'], "$folder/$name")) {
            throw new RuntimeException('Could not save the uploaded photo.');
        }
        return $name;
    }

    public static function pathFor(string $filename): ?string
    {
        // Re-check the name so a bad value can never point outside the folder.
        return preg_match(self::NAME_PATTERN, $filename) ? self::folder() . '/' . $filename : null;
    }

    public static function contentType(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        return array_search($extension, self::TYPES, true) ?: 'application/octet-stream';
    }

    // Type and size of a saved photo, shown on the report page. Null if the file is missing.
    // The user's original file name is never stored, so it cannot be shown.
    public static function describe(string $filename): ?array
    {
        $path = self::pathFor($filename);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $labels = ['image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/webp' => 'WebP'];
        return [
            'type' => $labels[self::contentType($filename)] ?? 'Image',
            'size' => (int) filesize($path),
        ];
    }

    public static function delete(string $filename): void
    {
        $path = self::pathFor($filename);
        if ($path !== null && is_file($path)) {
            unlink($path);
        }
    }

    private static function folder(): string
    {
        return dirname(__DIR__, 2) . '/uploads';
    }
}
