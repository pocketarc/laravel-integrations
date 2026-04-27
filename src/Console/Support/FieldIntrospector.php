<?php

declare(strict_types=1);

namespace Integrations\Console\Support;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Spatie\LaravelData\Data;

/**
 * Reads credential / metadata field shape out of a Spatie Data class and a
 * validation-rule map so InstallCommand can drive prompts generically.
 *
 * The Data class is the source of truth for types and defaults; rules-only
 * keys (when the provider has rules but no Data class) fall back to plain
 * strings.
 */
final class FieldIntrospector
{
    /**
     * @param  class-string<Data>|null  $dataClass
     * @param  array<string, mixed>  $rules
     * @return array<string, array{type: string, nullable: bool, hasDefault: bool, default: mixed, required: bool}>
     */
    public static function discover(?string $dataClass, array $rules): array
    {
        $fields = self::fromDataClass($dataClass);

        foreach ($rules as $key => $rule) {
            if (array_key_exists($key, $fields)) {
                continue;
            }

            $fields[$key] = [
                'type' => 'string',
                'nullable' => self::ruleIsNullable($rule),
                'hasDefault' => false,
                'default' => null,
                'required' => self::ruleIsRequired($rule),
            ];
        }

        return $fields;
    }

    /**
     * @param  class-string<Data>|null  $dataClass
     * @return array<string, array{type: string, nullable: bool, hasDefault: bool, default: mixed, required: bool}>
     */
    private static function fromDataClass(?string $dataClass): array
    {
        if ($dataClass === null) {
            return [];
        }

        $reflection = new ReflectionClass($dataClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $fields = [];

        foreach ($constructor->getParameters() as $parameter) {
            $fields[$parameter->getName()] = self::describe($parameter);
        }

        return $fields;
    }

    /**
     * @return array{type: string, nullable: bool, hasDefault: bool, default: mixed, required: bool}
     */
    private static function describe(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'string';
        $hasDefault = $parameter->isDefaultValueAvailable();
        $nullable = $parameter->allowsNull();

        return [
            'type' => $typeName,
            'nullable' => $nullable,
            'hasDefault' => $hasDefault,
            'default' => $hasDefault ? $parameter->getDefaultValue() : null,
            'required' => ! $nullable && ! $hasDefault,
        ];
    }

    private static function ruleIsNullable(mixed $rule): bool
    {
        if (is_string($rule)) {
            return str_contains($rule, 'nullable');
        }

        if (is_array($rule)) {
            foreach ($rule as $item) {
                if ($item === 'nullable') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Matches only the discrete `required` token, not conditional variants
     * like `required_if` / `required_with`, since those depend on sibling
     * field state we can't evaluate at prompt time.
     */
    private static function ruleIsRequired(mixed $rule): bool
    {
        if (is_string($rule)) {
            return in_array('required', explode('|', $rule), true);
        }

        if (is_array($rule)) {
            return in_array('required', $rule, true);
        }

        return false;
    }
}
