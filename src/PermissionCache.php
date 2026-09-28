<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions;

/**
 * The permission catalog cache — `Permissions::cache()`.
 *
 * The catalog invalidates itself on every grant and every role/permission save or delete (once
 * the surrounding transaction commits), so you rarely need this. Bulk writes that bypass model
 * events (`insert`, `upsert`, query-builder `delete`, `truncate`) are the exception: call
 * `forget()` after them.
 */
final readonly class PermissionCache
{
    public function __construct(private PermissionsManager $manager) {}

    /**
     * Drop the cached catalog from the shared store and this process's memo — now, and again
     * when the catalog connection's open transaction commits.
     */
    public function forget(): void
    {
        $this->manager->forgetCache();
    }

    /**
     * Drop only this process's memo, keeping the shared store. The package already does this
     * at every Octane request/task/tick and every queued job; call it at any other boundary
     * of a long-lived worker of your own.
     */
    public function flushMemo(): void
    {
        $this->manager->flushMemo();
    }
}
