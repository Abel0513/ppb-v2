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
        // Run 45687197017 / PR #120: id 4 is fourth by sort_id,id and its
        // November short settled rate (4–8, 33%) matches captured row 哆啦.
        return [4 => '哆啦', 6 => '黛秋', 7 => '洛老師'][$id] ?? '舊分析師 #'.$id;
    }
}
