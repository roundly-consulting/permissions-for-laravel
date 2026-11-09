<?php

declare(strict_types=1);

/**
 * The `about` line for the 2.1 surface: on/off, the scope key type and whether global grants
 * reach into scopes — config posture only, never a scope, owner or role name.
 */
it('reports assignments ON with their posture, and nothing about the host\'s scopes', function (): void {
    config()->set('permissions.assignments.scope_key_type', 'uuid');
    config()->set('permissions.assignments.global_applies_in_scope', false);
    config()->set('permissions.table_names.role_assignments', 'acme_project_grants');

    expect('permissions')->toLeakNoSecrets(
        secrets: ['acme_project_grants'],
        mustRender: ['Assignments', 'ON, scope key uuid, global grants do not apply in scopes', '1 renamed'],
    );
});
