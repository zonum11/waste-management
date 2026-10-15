<?php
// Handles the optional photo on a waste report, with strict upload checks.
class PhotoUpload
{
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)$/';

    public static function hasFile(?array $file): bool
    {
        return $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    // Returns an error message, or null when the photo is fine or missing.
    public static function validate(?array $file): ?string
    {
        if (!self::hasFile($file)) {
            return null;
        }
        if (is_array($file['error'])) {
            return 'Upload a single photo.';
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'Photo must be 2 MB or smaller.';
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return 'The photo could not be uploaded. Please try again.';
        }
        if ($file['size'] > self::MAX_BYTES) {
            return 'Photo must be 2 MB or smaller.';
        }

        // Check the real file contents, not the name or type the browser claims.
        $info = @getimagesize($file['tmp_name']);        if ($info === false || !isset(self::TYPES[$info['mime']])) {
            return 'Photo must be a JPEG, PNG or WebP image.';
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
