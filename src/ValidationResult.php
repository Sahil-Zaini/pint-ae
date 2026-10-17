<?php

declare(strict_types=1);

namespace PintAE;

use JsonSerializable;

/** Outcome of a validation run: errors block submission, warnings do not. */
final class ValidationResult implements JsonSerializable
{
    /** @var list<array{rule:string,severity:string,message:string,path:?string}> */
    private array $issues = [];

    public function error(string $rule, string $message, ?string $path = null): void
    {
        $this->issues[] = ['rule' => $rule, 'severity' => 'error', 'message' => $message, 'path' => $path];
    }

    public function warning(string $rule, string $message, ?string $path = null): void
    {
        $this->issues[] = ['rule' => $rule, 'severity' => 'warning', 'message' => $message, 'path' => $path];
    }

    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    /** @return list<array{rule:string,severity:string,message:string,path:?string}> */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, fn ($i) => $i['severity'] === 'error'));
    }

    /** @return list<array{rule:string,severity:string,message:string,path:?string}> */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, fn ($i) => $i['severity'] === 'warning'));
    }

    /** @return list<array{rule:string,severity:string,message:string,path:?string}> */
    public function all(): array
    {
        return $this->issues;
    }

    /** @return string[] rule ids that fired */
    public function rules(): array
    {
        return array_values(array_unique(array_column($this->issues, 'rule')));
    }

    public function hasRule(string $rule): bool
    {
        return in_array($rule, $this->rules(), true);
    }

    public function jsonSerialize(): array
    {
        return ['valid' => $this->isValid(), 'errors' => $this->errors(), 'warnings' => $this->warnings()];
    }

    public function __toString(): string
    {
        if ($this->issues === []) {
            return "VALID — no errors, no warnings\n";
        }
        $out = ($this->isValid() ? 'VALID' : 'INVALID') . sprintf(' — %d error(s), %d warning(s)', count($this->errors()), count($this->warnings())) . "\n";
        foreach ($this->issues as $i) {
            $out .= sprintf("  [%s] %-8s %s%s\n", $i['rule'], strtoupper($i['severity']), $i['message'], $i['path'] ? "  ({$i['path']})" : '');
        }
        return $out;
    }
}
