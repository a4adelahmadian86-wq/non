<?php

namespace App\Services;

class WorkflowConditionEvaluator
{
    public const FIELDS = [
        'event_type',
        'user_id',
        'customer_id',
        'order_id',
        'order_kind',
        'amount_rials',
        'total_rials',
        'status',
        'document_id',
        'revision',
        'ticket_id',
        'ticket_code',
        'product_id',
        'organization_id',
        'team_id',
    ];

    public const OPERATORS = [
        'exists',
        'equals',
        'not_equals',
        'in',
        'not_in',
        'contains',
        'greater_than',
        'greater_or_equal',
        'less_than',
        'less_or_equal',
    ];

    /**
     * Conditions are ANDed. The input is a constrained data format, never executable code.
     */
    public function matches(mixed $rawConditions, array $context): bool
    {
        if ($rawConditions === null || $rawConditions === '') {
            return true;
        }

        $conditions = is_array($rawConditions) ? $rawConditions : json_decode((string) $rawConditions, true);
        if (! is_array($conditions) || count($conditions) > 10) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                return false;
            }

            $field = (string) ($condition['field'] ?? '');
            $operator = (string) ($condition['operator'] ?? 'equals');
            if (! in_array($field, self::FIELDS, true) || ! in_array($operator, self::OPERATORS, true)) {
                return false;
            }

            $present = array_key_exists($field, $context);
            $actual = $context[$field] ?? null;
            $expected = $condition['value'] ?? null;

            $matched = match ($operator) {
                'exists' => $present && $actual !== null && $actual !== '',
                'equals' => $present && $actual == $expected,
                'not_equals' => $present && $actual != $expected,
                'in' => $present && in_array((string) $actual, $this->listValue($expected), true),
                'not_in' => $present && ! in_array((string) $actual, $this->listValue($expected), true),
                'contains' => is_array($actual)
                    ? in_array($expected, $actual, true)
                    : (is_string($actual) && is_string($expected) && str_contains($actual, $expected)),
                'greater_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
                'greater_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
                'less_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
                'less_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
                default => false,
            };

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private function listValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_map('strval', $value);
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($item) => $item !== ''));
    }
}
