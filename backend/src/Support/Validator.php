<?php

declare(strict_types=1);

namespace CouponFind\Support;

/**
 * Small rule-based validator. Throws HttpException(422) on failure with a
 * field => [messages] map. Rules: required, email, string, int, numeric,
 * min:N, max:N, in:a,b,c, confirmed, url, boolean.
 */
final class Validator
{
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    public static function make(array $data, array $rules): array
    {
        $v = new self($data);
        return $v->validate($rules);
    }

    public function validate(array $rules): array
    {
        $clean = [];
        foreach ($rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $ruleList = is_array($ruleString) ? $ruleString : explode('|', $ruleString);

            $isRequired = in_array('required', $ruleList, true);
            if (!$isRequired && ($value === null || $value === '')) {
                continue; // optional + empty => skip
            }

            foreach ($ruleList as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $this->applyRule($field, $value, $name, $param);
            }
            $clean[$field] = $value;
        }

        if ($this->errors) {
            throw HttpException::validation($this->errors);
        }
        return $clean;
    }

    private function applyRule(string $field, mixed $value, string $name, ?string $param): void
    {
        switch ($name) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
                    $this->fail($field, 'This field is required.');
                }
                break;
            case 'email':
                if ($value !== null && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    $this->fail($field, 'Must be a valid email address.');
                }
                break;
            case 'url':
                if ($value !== null && !filter_var((string) $value, FILTER_VALIDATE_URL)) {
                    $this->fail($field, 'Must be a valid URL.');
                }
                break;
            case 'int':
            case 'integer':
                if ($value !== null && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->fail($field, 'Must be an integer.');
                }
                break;
            case 'numeric':
                if ($value !== null && !is_numeric($value)) {
                    $this->fail($field, 'Must be numeric.');
                }
                break;
            case 'boolean':
                if ($value !== null && !in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                    $this->fail($field, 'Must be boolean.');
                }
                break;
            case 'min':
                if (is_string($value) && mb_strlen($value) < (int) $param) {
                    $this->fail($field, "Must be at least {$param} characters.");
                } elseif (is_numeric($value) && (float) $value < (float) $param) {
                    $this->fail($field, "Must be at least {$param}.");
                }
                break;
            case 'max':
                if (is_string($value) && mb_strlen($value) > (int) $param) {
                    $this->fail($field, "Must be at most {$param} characters.");
                } elseif (is_numeric($value) && (float) $value > (float) $param) {
                    $this->fail($field, "Must be at most {$param}.");
                }
                break;
            case 'in':
                $allowed = explode(',', (string) $param);
                if ($value !== null && !in_array((string) $value, $allowed, true)) {
                    $this->fail($field, 'Invalid value.');
                }
                break;
            case 'confirmed':
                if (($this->data[$field . '_confirmation'] ?? null) !== $value) {
                    $this->fail($field, 'Confirmation does not match.');
                }
                break;
        }
    }

    private function fail(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
