<?php
/**
 * NovaHire Input Validation & Sanitization Engine
 */
class NovaValidator {
    private array $errors = [];

    public function validateEmail(?string $email, string $field = 'email'): bool {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = 'Please provide a valid email address.';
            return false;
        }
        return true;
    }

    public function validateRequired(?string $value, string $fieldName): bool {
        if ($value === null || trim($value) === '') {
            $this->errors[$fieldName] = ucfirst($fieldName) . ' cannot be blank.';
            return false;
        }
        return true;
    }

    public function validateLength(string $value, int $min, int $max, string $field): bool {
        $len = mb_strlen(trim($value));
        if ($len < $min || $len > $max) {
            $this->errors[$field] = ucfirst($field) . " must be between {$min} and {$max} characters.";
            return false;
        }
        return true;
    }

    public function validateScore(int $score, int $min = 1, int $max = 100): bool {
        return ($score >= $min && $score <= $max);
    }

    public function sanitizeString(string $input): string {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    public function hasErrors(): bool {
        return !empty($this->errors);
    }

    public function getErrors(): array {
        return $this->errors;
    }
}