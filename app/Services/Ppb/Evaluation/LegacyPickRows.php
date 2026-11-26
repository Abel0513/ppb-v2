<?php

declare(strict_types=1);

namespace App\Services\Ppb\Evaluation;

use Generator;
use RuntimeException;

class LegacyPickRows
{
    private const PAGE_SIZE = 50;
    private const MAX_OFFSET = 5000;

    public function __construct(private LegacyReader $reader) {}

    public function select(array $columns, array $filters): Generator
    {
        $pageFilters = $filters;
        for ($offset = 0; ; $offset += self::PAGE_SIZE) {
            $data = $this->reader->query([
                'operation' => 'select', 'table' => 'pay.pay_analyst_pick',
                'columns' => ['id', ...$columns], 'filters' => $pageFilters,
                'limit' => self::PAGE_SIZE, 'offset' => $offset,
            ]);
            $rows = $data['rows'] ?? null;
            if (!is_array($rows)) {
                throw new RuntimeException('LEGACY_PICK_ROWS_MISSING');
            }

            yield from $rows;
            if (count($rows) < self::PAGE_SIZE) {
                return;
            }
            if ($offset === self::MAX_OFFSET) {
                // Continue beyond the reader's offset ceiling using its primary-key ordering.
                $lastId = (int) $rows[array_key_last($rows)]['id'];
                $pageFilters = [...$filters, ['column' => 'id', 'op' => '>', 'value' => $lastId]];
                $offset = -self::PAGE_SIZE;
            }
        }
    }
}
