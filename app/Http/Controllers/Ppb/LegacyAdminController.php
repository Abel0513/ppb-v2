<?php

namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Models\PpbAnalyst;
use App\Models\PpbGame;
use App\Services\Ppb\Evaluation\EvaluationService;
use App\Services\Ppb\Evaluation\EvaluationSubjects;
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

    private function month(Request $request): string
    {
        return $request->validate(['month' => ['nullable', 'date_format:Y-m', 'after_or_equal:2000-01', 'before_or_equal:2099-12']])['month']
            ?? now('Asia/Taipei')->format('Y-m');
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

    public function videos(Request $request, EvaluationSubjects $subjects)
    {
        $month = $this->month($request);
        $ready = Schema::hasTable('ppb_evaluation_videos');
        $date = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Taipei');
        $videos = $ready ? DB::table('ppb_evaluation_videos')
            ->where('scheduled_at', '>=', $date->utc()->toDateTimeString())
            ->where('scheduled_at', '<', $date->addMonth()->utc()->toDateTimeString())
            ->orderBy('scheduled_at')->orderBy('id')->get() : collect();
        [$roster, $unavailable] = $videos->isNotEmpty() ? $this->roster($subjects) : [collect(), false];

        $canManageVideos = $ready && $request->user('ppb')->hasPpbPermission('evaluations.view')
            && $request->user('ppb')->hasPpbPermission('evaluations.manage');

        return view('ppb.legacy-admin.videos', compact('month', 'ready', 'videos', 'roster', 'unavailable', 'canManageVideos'));
    }

    public function attendance(Request $request, EvaluationSubjects $subjects)
    {
        $ready = Schema::hasTable('ppb_evaluation_attendance');
        $query = DB::table('ppb_evaluation_attendance as a')->join('ppb_games as g', 'a.game_id', '=', 'g.id')
            ->whereIn('a.attendance_status', [1, 2]);
        $months = $ready ? (clone $query)->distinct()->orderByDesc('g.month_key')->pluck('g.month_key') : collect();
        $months = $months->prepend(now('Asia/Taipei')->format('Y-m'))->unique()->values();
        $pager = $this->monthPager($request, $months);
        $month = $pager->getCollection()->first() ?? now('Asia/Taipei')->format('Y-m');
        $attendance = $ready ? (clone $query)->where('g.month_key', $month)
            ->orderBy('g.recommended_at')->orderBy('a.id')
            ->get(['a.subject_type', 'a.subject_id', 'a.attendance_status', 'g.game_type', 'g.game_number',
                'g.matchup_text', 'g.league', 'g.away_team', 'g.home_team']) : collect();
        [$roster, $unavailable] = $attendance->isNotEmpty() ? $this->roster($subjects) : [collect(), false];

        return view('ppb.legacy-admin.attendance', compact('month', 'ready', 'attendance', 'pager', 'roster', 'unavailable'));
    }

    private function roster(EvaluationSubjects $subjects): array
    {
        try {
            return [collect($subjects->all())->keyBy(fn ($row) => $row['type'].':'.$row['id']), false];
        } catch (\RuntimeException $error) {
            report($error);
            return [collect(), true];
        }
    }

    public function report(Request $request, EvaluationSubjects $subjects)
    {
        $kind = $request->route()->defaults['kind'];
        $title = self::REPORT_TITLES[$kind];
        $month = $this->month($request);
        [$roster, $unavailable] = $this->roster($subjects);
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
            'kind', 'title', 'month', 'roster', 'profiles', 'canSeeLineName', 'canSeeWork', 'unavailable'
        ))->header('Cache-Control', 'private, no-store');
    }

    public function summary(Request $request, string $type, int $id, EvaluationSubjects $subjects, EvaluationService $service)
    {
        $month = $this->month($request);
        $subject = $subjects->get($type, $id);
        $report = $service->load($subject, $month);
        $current = $report['current'];
        $kind = $request->route()->defaults['kind'];

        if ($kind === 'schedule') {
            $values = [
                'rating' => LegacyDisplayLabels::rating($current['level'] ?? ($month === now('Asia/Taipei')->format('Y-m') ? ($subject['rating_level'] ?? null) : null)),
                'score' => $current['view']['total_score'],
                'performance' => $current['performance'],
            ];
        } else {
            $level = $current['level'];
            $next = $report['recorded_next_level'] ?? null;
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
