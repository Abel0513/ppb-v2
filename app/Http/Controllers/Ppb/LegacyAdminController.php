<?php

namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Models\PpbAnalyst;
use App\Models\PpbGame;
use App\Services\Ppb\Evaluation\EvaluationService;
use App\Services\Ppb\Evaluation\EvaluationInputs;
use App\Services\Ppb\Evaluation\LocalEvaluationData;
use App\Services\Ppb\LegacyDisplayLabels;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyAdminController extends Controller
{
    private const REPORT_TITLES = [
        'analyst-list' => '分析師列表',
        'performance' => '戰績排行榜',
        'rating-salary' => '薪資/評級異動',
        'salary' => '分析師薪資',
        'rating' => '分析師評級異動',
    ];

    private function month(Request $request, bool $previous = false): string
    {
        return $request->validate(['month' => ['nullable', 'date_format:Y-m', 'after_or_equal:2000-01', 'before_or_equal:2099-12']])['month']
            ?? now('Asia/Taipei')->startOfMonth()->subMonths((int) $previous)->format('Y-m');
    }

    public function articles(Request $request)
    {
        $query = PpbGame::query()->where('has_long_article', true);
        $months = (clone $query)->distinct()->orderByDesc('month_key')->pluck('month_key');
        $pager = $this->monthPager($request, $months);
        $month = $pager->getCollection()->first();
        $games = $month ? $query->where('month_key', $month)->with(['assignments' => fn ($rows) =>
            $rows->where('kind', 'long')->where('status', 'active')->with('currentAnalyst')])
            ->orderBy('recommended_at')->orderBy('game_type')->orderBy('game_number')->orderBy('id')->get() : collect();

        return view('ppb.legacy-admin.articles', compact('month', 'pager', 'games'));
    }

    private function monthPager(Request $request, $months): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('p', 1));
        return new LengthAwarePaginator($months->forPage($page, 1), $months->count(), 1, $page, [
            'path' => $request->url(), 'pageName' => 'p',
        ]);
    }

    public function videos(Request $request, LocalEvaluationData $data)
    {
        $month = $this->month($request);
        $ready = Schema::hasTable('ppb_evaluation_videos');
        $date = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Taipei');
        $videos = $ready ? DB::table('ppb_evaluation_videos')
            ->where('scheduled_at', '>=', $date->utc()->toDateTimeString())
            ->where('scheduled_at', '<', $date->addMonth()->utc()->toDateTimeString())
            ->orderBy('scheduled_at')->orderBy('id')->get() : collect();
        $roster = collect($data->roster());

        $canManageVideos = $data->ready() && Schema::hasColumn('ppb_evaluation_videos', 'week_no') && $request->user('ppb')->hasPpbPermission('evaluations.view')
            && $request->user('ppb')->hasPpbPermission('evaluations.manage');

        return view('ppb.legacy-admin.videos', compact('month', 'ready', 'videos', 'roster', 'canManageVideos'));
    }

    public function attendance(Request $request, LocalEvaluationData $data, EvaluationInputs $inputs)
    {
        $ready = Schema::hasTable('ppb_evaluation_attendance');
        $roster = collect($data->roster());
        $rows = $ready ? collect($inputs->attendanceRows($data->records(attendanceOnly: true), $data->corrections()))
            ->filter(fn ($row) => $roster->has($row['subject_type'].':'.$row['subject_id'])) : collect();
        $byMonth = $rows->groupBy(fn ($row) => substr($row['recommended_time'], 0, 7));
        $current = now('Asia/Taipei')->format('Y-m');
        $months = $byMonth->keys()->push($current)->unique()->sortDesc()->values();
        $pager = $this->monthPager($request, $months);
        $month = $request->has('month') ? $this->month($request) : ($pager->getCollection()->first() ?? $current);
        $selected = $byMonth->get($month, collect());
        $games = PpbGame::whereIn('id', $selected->pluck('gid'))->get()->keyBy('id');
        $attendance = $selected->map(fn ($row) => (object) ($row + (($game = $games->get($row['gid'])) ? $game->getAttributes() : [])));
        $unavailable = false;

        return view('ppb.legacy-admin.attendance', compact('month', 'ready', 'attendance', 'pager', 'roster', 'unavailable', 'months'));
    }

    public function report(Request $request, LocalEvaluationData $data, EvaluationService $service)
    {
        $kind = $request->route()->defaults['kind'];
        $title = self::REPORT_TITLES[$kind];
        $isPay = in_array($kind, ['rating-salary', 'salary', 'rating'], true);
        $month = $this->month($request, $isPay);
        $roster = collect($data->roster());
        $unavailable = !$data->ready();
        $context = $unavailable ? null : $data->context(CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Taipei'));
        $values = $roster->map(function ($subject) use ($service, $context, $month, $isPay) {
            if ($context === null) return [];
            $report = $service->loadLocal($subject, $month, $context);
            $current = $report['current'];
            $level = $current['level'] === null ? null : (int) $current['level'];
            if ($isPay) {
                $next = $report['recorded_next_level'] ?? ($level !== null ? ($current['next_rating']['rating_level'] ?? null) : null);
                return $current['salary'] + [
                    'rating' => LegacyDisplayLabels::rating($level),
                    'next_rating' => LegacyDisplayLabels::rating($next),
                    'change' => $level === null || $next === null ? '無資料' : ['降級', '維持', '升級'][($next <=> $level) + 1],
                    ...array_combine(['score_0', 'score_1', 'score_2'], array_reverse(array_column(array_column($report['history'], 'rating'), 'total_score'))),
                ];
            }
            if ($level === null && $month === now('Asia/Taipei')->format('Y-m') && isset($subject['rating_level'])) {
                $level = (int) $subject['rating_level'];
            }
            $row = ['rating' => LegacyDisplayLabels::rating($level), 'score' => $current['view']['total_score']];
            foreach (['month' => 'month', 'recent' => 'recent10', 'bonus' => 'bonus'] as $prefix => $period) {
                $stats = $current['performance'][$period];
                $row[$prefix.'_rate'] = $stats['rate'];
                $row[$prefix.'_record'] = $stats['wins'] === null ? null : $stats['wins'].' - '.$stats['losses'];
            }
            return $row;
        });
        $canSeeLineName = $kind === 'analyst-list'
            && $request->user('ppb')->hasPpbPermission('schedules.manage')
            && $request->user('ppb')->hasPpbPermission('accounts.manage');
        $ids = $roster->pluck('native_id')->filter()->unique();
        $profiles = collect();
        if ($ids->isNotEmpty()) {
            // The master field migrations may still be pending on a no-migrate release.
            $available = Schema::getColumnListing('ppb_analysts');
            $permitted = $canSeeLineName ? ['color', 'background', 'line_name'] : ['color', 'background'];
            $columns = ['id', ...array_values(array_intersect($permitted, $available))];
            $profiles = PpbAnalyst::whereIn('id', $ids)->get($columns)->keyBy('id');
        }
        $canSeeWork = $request->user('ppb')->hasPpbPermission('evaluations.view');

        return response()->view('ppb.legacy-admin.report', compact(
            'kind', 'title', 'month', 'roster', 'profiles', 'canSeeLineName', 'canSeeWork', 'unavailable', 'values'
        ))->header('Cache-Control', 'private, no-store');
    }

    public function summary(Request $request, string $type, int $id, LocalEvaluationData $data, EvaluationService $service)
    {
        $kind = $request->route()->defaults['kind'];
        $month = $this->month($request, $kind !== 'schedule');
        $subject = $data->roster()[$type.':'.$id] ?? abort(404);
        abort_unless($data->ready(), 503);
        $report = $service->loadLocal($subject, $month);
        $current = $report['current'];

        if ($kind === 'schedule') {
            $values = [
                'rating' => LegacyDisplayLabels::rating($current['level'] ?? ($month === now('Asia/Taipei')->format('Y-m') ? ($subject['rating_level'] ?? null) : null)),
                'score' => $current['view']['total_score'],
                'performance' => $current['performance'],
            ];
        } else {
            $level = $current['level'];
            $next = $report['recorded_next_level'] ?? ($level !== null ? ($current['next_rating']['rating_level'] ?? null) : null);
            $values = [
                'salary' => $current['salary'],
                'rating' => LegacyDisplayLabels::rating($level),
                'next_rating' => LegacyDisplayLabels::rating($next),
                'change' => $level === null || $next === null ? '無資料' : ['降級', '維持', '升級'][($next <=> $level) + 1],
                'scores' => array_values(array_reverse(array_map(
                    fn ($period) => $period['rating']['total_score'], $report['history']
                ))),
            ];
        }
        return response()->json($values, 200, ['Cache-Control' => 'private, no-store']);
    }

    public function csda()
    {
        return view('ppb.legacy-admin.csda');
    }
}
