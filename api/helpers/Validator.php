<?php
// Checks user input on the server, because the browser cannot be trusted.
class Validator
{
    private array $errors = [];

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    // Lets a controller record an error from its own check.
    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function name(array $data, string $field, string $label): string
    {
        $value = $this->read($data, $field);
        if ($value === '') {
            $this->errors[$field] = "$label is required.";
        } elseif (mb_strlen($value) > 50) {
            $this->errors[$field] = "$label must be 50 characters or fewer.";
        } elseif (!preg_match("/^[\p{L}\s.'-]+$/u", $value)) {
            $this->errors[$field] = "$label may only contain letters, spaces, apostrophes, dots and hyphens.";
        }
        return $value;
    }

    public function email(array $data, string $field = 'email'): string
    {
        $value = strtolower($this->read($data, $field));
        if ($value === '') {
            $this->errors[$field] = 'Email is required.';
        } elseif (mb_strlen($value) > 100 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = 'Enter a valid email address.';
        }
        return $value;
    }

    // Optional field: an empty value is allowed.
    public function phone(array $data, string $field = 'phone_number'): string
    {
        $value = $this->read($data, $field);
        if ($value !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $value)) {
            $this->errors[$field] = 'Enter a valid phone number (7-20 digits, + - ( ) allowed).';
        }
        return $value;
    }

    public function address(array $data, string $field = 'address'): string
    {
        $value = $this->read($data, $field);
        if ($value === '') {
            $this->errors[$field] = 'Address is required.';
        } elseif (mb_strlen($value) > 255) {
            $this->errors[$field] = 'Address must be 255 characters or fewer.';
        }
        return $value;
    }

    public function newPassword(array $data, string $field = 'password'): string
    {
        $value = $data[$field] ?? '';
        $value = is_string($value) ? $value : '';

        if (strlen($value) < 8) {
            $this->errors[$field] = 'Password must be at least 8 characters.';
        } elseif (strlen($value) > 72) {
            // bcrypt only reads the first 72 bytes of a password.
            $this->errors[$field] = 'Password must be 72 characters or fewer.';
        } elseif (!preg_match('/[A-Za-z]/', $value) || !preg_match('/[0-9]/', $value)) {
            $this->errors[$field] = 'Password must contain at least one letter and one number.';
        }
        return $value;
    }

    public function oneOf(array $data, string $field, string $label, array $allowed, ?string $default = null): string
    {
        $value = $this->read($data, $field);
        if ($value === '' && $default !== null) {
            return $default;
        }
        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = "Choose a valid $label.";
        }
        return $value;
    }

    // Turns "07:30" into "07:30:00", the format MySQL's TIME uses.
    public function time(array $data, string $field, string $label): string
    {
        $value = $this->read($data, $field);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            $this->errors[$field] = "Enter a valid $label (for example 07:30).";
            return '';
        }
        return $value . ':00';
    }

    // Accepts 3 or "3" (dropdown values arrive as text).
    public function id(array $data, string $field, string $label): int
    {
        $value = $data[$field] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }
        $this->errors[$field] = "Choose a valid $label.";
        return 0;
    }

    public function longText(array $data, string $field, string $label, int $min, int $max): string
    {
        $value = $this->read($data, $field);
        $length = mb_strlen($value);
        if ($value === '') {
            $this->errors[$field] = "$label is required.";
        } elseif ($length < $min) {
            $this->errors[$field] = "$label must be at least $min characters.";
        } elseif ($length > $max) {
            $this->errors[$field] = "$label must be $max characters or fewer.";
        }
        return $value;
    }

    public function optionalText(array $data, string $field, string $label, int $max): string
    {
        $value = $this->read($data, $field);
        if (mb_strlen($value) > $max) {
            $this->errors[$field] = "$label must be $max characters or fewer.";
        }
        return $value;
    }

    // A list of ids like [1, 3]; duplicates are removed.
    public function idList(array $data, string $field, string $label, bool $required = true): array
    {
        $value = $data[$field] ?? null;
        $ids = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_int($item) && $item > 0) {
                    $ids[] = $item;
                } elseif (is_string($item) && ctype_digit($item) && (int) $item > 0) {
                    $ids[] = (int) $item;
                } else {
                    $this->errors[$field] = "$label contains an invalid value.";
                    return [];
                }
            }
        }

        $ids = array_values(array_unique($ids));
        if (!$ids && $required) {
            $this->errors[$field] = "Select at least one of $label.";
        } elseif (count($ids) > 50) {
            $this->errors[$field] = "Too many values selected for $label.";
        }
        return $ids;
    }

    // Reads a text field safely: non-text becomes '' and edge spaces are trimmed.
    private function read(array $data, string $field): string
    {
        $value = $data[$field] ?? '';
        return is_string($value) ? trim($value) : '';
    }
}
