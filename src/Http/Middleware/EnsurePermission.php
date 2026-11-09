<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Permissions\Concerns\HasRoles;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\PermissionsManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: the signed-in user must have a permission — optionally inside the scope a
 * route parameter is bound to.
 *
 *     Route::put('/projects/{project}/posts/{post}', …)
 *         ->middleware(EnsurePermission::using('posts.edit', scope: 'project'));
 *     // or: ->middleware(EnsurePermission::class.':posts.edit,project')
 *
 * Without a scope parameter it checks unscoped (`hasPermissionTo()`). The scope parameter must be
 * an implicitly bound model; a missing or unbound one is a route bug and throws a
 * {@see PermissionException} rather than guessing. Denials are a 403 (`AuthorizationException`).
 *
 * It asks the permission registry directly, not the Gate, so it works with
 * `register_gate_check` off and ignores `resolveScopeUsing()` — use `can:` middleware for that.
 * No alias is registered; reference the class.
 */
final class EnsurePermission
{
    public function __construct(private readonly PermissionsManager $permissions) {}

    /** The middleware string for a route: `EnsurePermission::using('posts.edit', scope: 'project')`. */
    public static function using(string $permission, ?string $scope = null): string
    {
        return self::class.':'.$permission.($scope === null ? '' : ','.$scope);
    }

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string $permission, ?string $scope = null): Response
    {
        $user = $request->user();

        if (! $user instanceof Model || ! in_array(HasRoles::class, class_uses_recursive($user), true)) {
            throw new AuthorizationException;
        }

        $grants = $this->permissions->for($user);

        if ($scope !== null && $scope !== '') {
            $model = $request->route($scope);

            if (! $model instanceof Model) {
                throw PermissionException::missingScopeParameter($scope);
            }

            $grants = $grants->in($model);
        }

        if (! $grants->hasPermissionTo($permission)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
