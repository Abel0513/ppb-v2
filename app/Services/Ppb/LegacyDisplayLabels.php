<?php

namespace App\Services\Ppb;

final class LegacyDisplayLabels
{
    public static function rating(?int $level): string
    {
        return [3 => 'S', 2 => 'A', 1 => 'B', 0 => 'C'][$level] ?? '無資料';
    }

    public static function analystType(?int $level): string
    {
        return [1 => '💎 資深分析師', 0 => '🌱 新銳分析師'][$level] ?? '無資料';
    }

    public static function unmappedAnalyst(int $id): string
    {
        // Verified against option values in the 2026-11-26 legacy shift export.
        return [4 => '哆啦', 6 => '葉秋', 7 => '洛老師', 18 => '潔西'][$id] ?? '舊分析師 #'.$id;
    }
}
