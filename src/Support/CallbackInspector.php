<?php

declare(strict_types=1);

namespace Integrations\Support;

use Closure;
use Integrations\RequestContext;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Reflection helpers for deciding whether to inject a `RequestContext`
 * into a user closure. Plain closures tolerate extra positional args, but
 * invokables and `Closure::fromCallable()` on a strict-arity method throw
 * `ArgumentCountError`. The first parameter's declared type also matters:
 * `fn (string $id) => ...` is one-arg but mustn't get a RequestContext
 * shoved in.
 */
final class CallbackInspector
{
    /**
     * @param  Closure(RequestContext=): mixed  $callback
     */
    public static function acceptsContext(Closure $callback): bool
    {
        $parameters = (new ReflectionFunction($callback))->getParameters();
        if ($parameters === []) {
            return false;
        }

        $type = $parameters[0]->getType();

        if ($type === null) {
            // Untyped first parameter: the lambda-style
            // `fn ($ctx) => $ctx->idempotencyKey` ergonomics rely on this.
            return true;
        }

        if ($type instanceof ReflectionNamedType) {
            return self::namedTypeAcceptsContext($type);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $sub) {
                if ($sub instanceof ReflectionNamedType && self::namedTypeAcceptsContext($sub)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function namedTypeAcceptsContext(ReflectionNamedType $type): bool
    {
        $name = $type->getName();

        if ($name === 'mixed' || $name === 'object') {
            return true;
        }

        return ! $type->isBuiltin() && is_a(RequestContext::class, $name, true);
    }
}
