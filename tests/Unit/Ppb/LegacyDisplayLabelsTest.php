<?php

namespace Tests\Unit\Ppb;

use App\Services\Ppb\LegacyDisplayLabels;
use PHPUnit\Framework\TestCase;

class LegacyDisplayLabelsTest extends TestCase
{
    public function test_rating_and_analyst_type_have_independent_domains(): void
    {
        self::assertSame(['C', 'B', 'A', 'S', '無資料', '無資料'], array_map(
            LegacyDisplayLabels::rating(...), [0, 1, 2, 3, 4, null]
        ));
        self::assertSame(['🌱 新銳分析師', '💎 資深分析師', '無資料', '無資料'], array_map(
            LegacyDisplayLabels::analystType(...), [0, 1, 2, null]
        ));
    }

    public function test_only_the_verified_unmapped_id_has_a_specific_name(): void
    {
        self::assertSame('哆啦', LegacyDisplayLabels::unmappedAnalyst(4));
        self::assertSame('舊分析師 #5', LegacyDisplayLabels::unmappedAnalyst(5));
    }
}
