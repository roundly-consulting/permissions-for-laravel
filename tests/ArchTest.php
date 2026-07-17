<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * This package shipped three arch rules: a vendor allow-list, strict types, and an
 * exception-namespace pin. It had **no finality rule and no seam rule at all** — which is
 * notable, because permissions is the package the seam preset was built from: its
 * `findOrCreate()` used `static::query()`, so a host that swapped the model got rows
 * created as the packaged class, and **authorization broke** (#31/#34).
 */
ArchPresets::strictTypes('RoundlyConsulting\Permissions');

/**
 * The deliberate tension, run as a pair. `finalByDefault` wants every class closed;
 * `swappableModelsAreNotFinal` forbids `final` on a config-swappable model — a PHP fatal
 * the moment a host uses the seam the config documents, shipped 7x across the fleet under
 * green "everything is final" arch tests.
 *
 * Exempt from the first: Role and Permission, which `permissions.models.*` explicitly
 * invites a host to subclass ("Swap either model for a subclass"), and PermissionException,
 * the base every permissions error extends so a host can catch them uniformly.
 */
ArchPresets::finalByDefault('RoundlyConsulting\Permissions')
    ->ignoring([Role::class, Permission::class, PermissionException::class]);

/**
 * The counter-weight. Also pins that each key really defaults to the packaged model, so
 * the seam cannot rot in the other direction either.
 */
ArchPresets::swappableModelsAreNotFinal([
    Role::class => 'permissions.models.role',
    Permission::class => 'permissions.models.permission',
]);

/**
 * The preset this package is the reason for — #34: `findOrCreate()` resolved
 * `static::query()`, i.e. the CALLED class rather than the CONFIGURED one, so a host
 * subclass swap was ignored and the row was created as the packaged class. Eloquent keys
 * model events on the concrete class, so the registrar's catalog-cache invalidation never
 * fired and **authorization broke**.
 *
 * **The keys MUST be declared here.** Undeclared, the stray-literal half infers swap keys
 * from key *shape* — `model`, `models`, or `*_model`. This package's keys are
 * `permissions.models.role` and `permissions.models.permission`: neither is shaped like
 * that, so the preset would have inferred NOTHING and gone green while covering none of
 * the two seams it exists for — authoritative-looking and completely inert. That is the
 * alerts trap, and this package would have been its worst instance. A declared key that
 * matches no literal fails rather than pretending to cover something.
 */
ArchPresets::modelsResolveThroughSeam(__DIR__.'/../src', 'Support', [
    'permissions.models.role',
    'permissions.models.permission',
]);

/**
 * The Dependency Policy as a test — this package had no such rule. No `alsoAllow`: its
 * `require` ships only php/illuminate/roundly, and the workflow installs test tooling with
 * `--dev`. If this goes red the shipped graph is wrong; never widen the allow-list to
 * quiet it.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

ArchPresets::noDebuggingLeftovers();

/**
 * Bespoke, kept — no preset equivalent.
 *
 * Guard against any third-party runtime vendor sneaking into src/. Allow-listing only the
 * sanctioned roots (Laravel/Symfony, our own roundly packages, PHP built-ins) bans every
 * non-whitelisted vendor implicitly, without naming one.
 */
arch('src only uses allowed vendor roots')
    ->expect('RoundlyConsulting\Permissions')
    ->toOnlyUse([
        'RoundlyConsulting\Permissions',
        'RoundlyConsulting\Permissions\Database\Factories',
        'RoundlyConsulting\Enums',
        'RoundlyConsulting\PackageToolkit',
        'RoundlyConsulting\Translatable',
        'Illuminate',
        'Carbon',
        'BackedEnum',
        'RuntimeException',
        // native helpers used unqualified
        'app',
        'class_basename',
        'config',
        'event',
        '__',
    ]);

arch('exceptions live in an Exceptions namespace')
    ->expect('RoundlyConsulting\Permissions\Exceptions')
    ->toExtend('RuntimeException');
