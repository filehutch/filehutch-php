<?php

declare(strict_types=1);

namespace FileHutch\Laravel;

use Illuminate\Http\Request;

/**
 * Who may use the direct-upload routes. Closed until you say:
 *
 *   DirectUploads::authorize(fn (Request $request, string $policy) =>
 *       $request->user() !== null && in_array($policy, ['avatars', 'documents'], true));
 *
 * The callback runs on both calls. On the first, `$policy` is the one being
 * requested. On the second there is no policy in the request, so it is looked
 * up from the file being finalized, never taken from the client, which could
 * otherwise name a policy it likes to finish an upload made under one it may
 * not use. A callback that only takes the request skips that lookup.
 */
final class DirectUploads
{
    /** @var (\Closure(Request, string): bool)|(\Closure(Request): bool)|null */
    private static ?\Closure $authorizer = null;

    public static function authorize(?callable $callback): void
    {
        self::$authorizer = $callback === null ? null : \Closure::fromCallable($callback);
    }

    /** @internal */
    public static function authorizer(): ?\Closure
    {
        return self::$authorizer;
    }

    /** @internal Whether the callback wants a policy, and so whether complete must look one up. */
    public static function wantsPolicy(\Closure $callback): bool
    {
        $reflection = new \ReflectionFunction($callback);

        return $reflection->isVariadic() || $reflection->getNumberOfParameters() >= 2;
    }
}
