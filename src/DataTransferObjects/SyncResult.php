<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\DataTransferObjects;

/**
 * What `Permissions::syncFrom()` / `syncRolesFrom()` did: which enum cases became new rows and
 * which already existed. Both lists hold names in the enum's case order.
 */
final readonly class SyncResult
{
    /**
     * @param  list<string>  $created  names registered by this sync
     * @param  list<string>  $existing  names that were already registered
     */
    public function __construct(
        public array $created,
        public array $existing,
    ) {}

    /** Whether the sync registered anything new. */
    public function changed(): bool
    {
        return $this->created !== [];
    }
}
