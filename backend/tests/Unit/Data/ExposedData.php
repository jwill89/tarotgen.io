<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use Tarot\Data\AbstractData;

/**
 * Concrete subclass that exposes the protected helpers so we can exercise the
 * shared Data-layer plumbing against an in-memory SQLite database.
 */
final class ExposedData extends AbstractData
{
    public function callApplyUpdate(
        string $table,
        array $data,
        array $allowed,
        array $where,
        array $intCols = [],
        array $boolCols = []
    ): bool {
        return $this->applyUpdate($table, $data, $allowed, $where, $intCols, $boolCols);
    }
}
