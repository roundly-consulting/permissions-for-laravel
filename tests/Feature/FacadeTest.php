<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Facades\Permissions;

/**
 * The Actions → Manager → Facade contract, pinned: the facade documents every public
 * PermissionsManager method (the grant/cache funnels the `for()` handle and `cache()` call are
 * `@internal`), `Permissions::fake()` swaps in a PermissionsFake that subtypes the manager (so
 * injected managers and the traits get it too), and every action under src/Actions is
 * reachable from the facade.
 */
it('pins the permissions facade contract', function (): void {
    expect(Permissions::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});
