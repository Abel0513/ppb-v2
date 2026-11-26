<?php

namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Models\PpbAnalyst;
use App\Models\PpbGame;
use App\Services\Ppb\Evaluation\EvaluationInputs;
use App\Services\Ppb\Evaluation\EvaluationService;
use App\Services\Ppb\Evaluation\EvaluationSubjects;
use App\Services\Ppb\Evaluation\LegacyAttendanceSource;
use App\Services\Ppb\Evaluation\LegacyReader;
use App\Services\Ppb\Evaluation\LocalEvaluationData;
use App\Services\Ppb\LegacyDisplayLabels;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class LegacyAdminController extends Controller
{
    private const REPORT_TITLES = [
        'analyst-list' => '分析師列表',
        'performance' => '戰績排行榜',
        'rating-salary' => '薪資／評級異動',
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

    public function attendance(Request $request, LocalEvaluationData $data, EvaluationInputs $inputs, LegacyAttendanceSource $source)
    {
        $requestedMonth = $request->has('month') ? $this->month($request) : null;
        $ready = Schema::hasTable('ppb_evaluation_attendance');
        $roster = collect($data->roster());
        $native = $ready ? collect($inputs->attendanceRows($data->records(attendanceOnly: true), $data->corrections()))
            ->filter(fn ($row) => $roster->has($row['subject_type'].':'.$row['subject_id'])) : collect();

        $unavailable = false;
        try {
            $legacy = collect($source->rows());
        } catch (\Exception $error) {
            report($error);
            $legacy = collect();
            $unavailable = true;
        }

        $games = PpbGame::whereIntegerInRaw('id', $native->concat($legacy)->pluck('gid')->unique()->all())->get()->keyBy('id');
        $imported = collect();
        if ($legacy->isNotEmpty()) {
            $requestedIds = $legacy->pluck('gid')->flip();
            $imported = PpbGame::where('notes', 'like', '%"legacy_source_schedule_id"%')->get()
                ->groupBy(fn ($game) => json_decode($game->notes, true)['legacy_source_schedule_id'] ?? 0)
                ->filter(fn ($games, $id) => $requestedIds->has($id));
        }
        $key = fn ($row, $gameId = null) => ($gameId ?? $row['gid']).':'.$row['subject_type'].':'.$row['subject_id'];
        $nativeKeys = $native->keyBy(fn ($row) => $key($row));
        $legacy = $legacy->unique(fn ($row) => $key($row))->map(function ($row) use ($games, $imported, $source) {
            $linked = $imported->get($row['gid']);
            $game = $linked === null ? $games->get($row['gid']) : ($linked->count() === 1 ? $linked->first() : null);

            return $row + ['game' => $source->matchesImportedGame($row, $game) ? $game : null];
        })->reject(fn ($row) => $row['game'] && $nativeKeys->has($key($row, $row['game']->id)));

        $byMonth = $legacy->concat($native->map(function ($row) use ($games) {
            $game = $games->get($row['gid']);

            return $row + ['game' => $game, 'code' => $game->display_code];
        }))->groupBy(fn ($row) => substr($row['recommended_time'], 0, 7));

        $current = now('Asia/Taipei')->format('Y-m');
        $months = $byMonth->keys();
        if ($unavailable) {
            $months->push($current);
        }
        $months = $months->unique()->sortDesc()->values();
        $monthIndex = $months->search($requestedMonth, true);
        $page = $monthIndex === false ? (int) $request->query('p', 1) : $monthIndex + 1;
        $page = max(1, min($months->count(), $page));
        $pager = new LengthAwarePaginator($months->forPage($page, 1), $months->count(), 1, $page, [
            'path' => $request->url(), 'pageName' => 'p',
        ]);
        $month = $pager->getCollection()->first() ?? $current;
        $selected = $byMonth->get($month, collect())->sortBy(['recommended_time', 'gid']);
        $actor = $request->user('ppb');
        $canCorrect = $actor->hasPpbPermission('evaluations.view') && $actor->hasPpbPermission('evaluations.manage');
        $attendance = $selected->map(function ($row) use ($roster, $canCorrect, $month) {
            $subject = $roster->get($row['subject_type'].':'.$row['subject_id']);
            $correctionUrl = $canCorrect && $row['source'] !== 'legacy'
                ? route('ppb.evaluations.show', ['type' => $row['subject_type'], 'id' => $row['subject_id'], 'month' => $month]).'#evaluation-attendance'
                : null;

            return $row + [
                'name' => $subject['label'] ?? LegacyDisplayLabels::unmappedAnalyst($row['subject_id']),
                'correctionUrl' => $correctionUrl,
            ];
        });

        return view('ppb.legacy-admin.attendance', compact('month', 'ready', 'attendance', 'pager', 'unavailable'));
    }

    public function report(Request $request, EvaluationSubjects $subjects, LocalEvaluationData $data, EvaluationService $service)
    {
        $kind = $request->route()->defaults['kind'];
        $title = self::REPORT_TITLES[$kind];
        $isPay = in_array($kind, ['rating-salary', 'salary', 'rating'], true);
        $month = $this->month($request, $isPay);
        $unavailable = false;
        try {
            $roster = collect($subjects->all());
        } catch (\Exception $error) {
            report($error);
            $roster = collect();
            $unavailable = true;
        }
        $context = $roster->contains('type', 'v2') && $data->ready()
            ? $data->context(CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Taipei'))
            : null;
        $legacySubjects = $roster->where('type', 'legacy');
        $cacheKeys = $legacySubjects->flatMap(fn ($subject) => [
            $this->summaryCacheKey($subject, $month), $this->lastSummaryCacheKey($subject, $month),
        ]);
        try {
            $cached = $cacheKeys->isEmpty() ? [] : Cache::many($cacheKeys->values()->all());
        } catch (\Throwable $error) {
            report($error);
            $cached = [];
        }
        $values = $roster->mapWithKeys(function (array $subject) use ($service, $context, $month, $isPay, $cached) {
            $key = $subject['type'].':'.$subject['id'];
            if ($subject['type'] === 'legacy') {
                $summary = $cached[$this->summaryCacheKey($subject, $month)] ?? null;
                if ($summary === null) {
                    // Show the last known values at once and let the browser refresh them;
                    // only a never-loaded row starts as 載入中.
                    $last = $cached[$this->lastSummaryCacheKey($subject, $month)] ?? null;
                    return [$key => is_array($last)
                        ? $this->reportCells($last[$isPay ? 'rating' : 'schedule'], $isPay) + ['_stale' => true]
                        : null];
                }
            } elseif ($context === null) {
                return [$key => ['failed' => true]];
            } else {
                $summary = $this->reportSummary($service->loadLocal($subject, $month, $context), $subject, $month);
            }

            return [$key => $this->reportCells($summary[$isPay ? 'rating' : 'schedule'], $isPay)];
        });
        $canSeeLineName = $kind === 'analyst-list'
            && $request->user('ppb')->hasPpbPermission('schedules.manage')
            && $request->user('ppb')->hasPpbPermission('accounts.manage');
        $ids = $roster->pluck('native_id')->filter()->unique();
        $profiles = collect();
        if ($canSeeLineName && $ids->isNotEmpty() && Schema::hasColumn('ppb_analysts', 'line_name')) {
            $profiles = PpbAnalyst::whereIn('id', $ids)->get(['id', 'line_name'])->keyBy('id');
        }
        $canSeeWork = $request->user('ppb')->hasPpbPermission('evaluations.view');
        $legacyLineNames = [];
        if ($canSeeLineName && Schema::hasTable('ppb_legacy_analyst_profiles')) {
            $legacyLineNames = DB::table('ppb_legacy_analyst_profiles')->pluck('line_name', 'legacy_analyst_id')->all();
        }

        return response()->view('ppb.legacy-admin.report', compact(
            'kind', 'title', 'month', 'roster', 'profiles', 'canSeeLineName', 'canSeeWork', 'unavailable', 'values', 'isPay',
            'legacyLineNames'
        ))->header('Cache-Control', 'private, no-store');
    }

    public function summary(Request $request, string $type, int $id, EvaluationSubjects $subjects, LocalEvaluationData $data, EvaluationService $service)
    {
        $kind = $request->route()->defaults['kind'];
        $month = $this->month($request, $kind === 'rating');
        try {
            $subject = $subjects->get($type, $id);
            if ($subject['type'] === 'legacy') {
                $ttl = $month === now('Asia/Taipei')->format('Y-m') ? 300 : 600;
                $summary = Cache::remember($this->summaryCacheKey($subject, $month), $ttl,
                    fn () => $this->reportSummary($service->load($subject, $month), $subject, $month));
                Cache::put($this->lastSummaryCacheKey($subject, $month), $summary, now()->addDays(2));
            } elseif ($data->ready()) {
                $summary = $this->reportSummary($service->loadLocal($subject, $month), $subject, $month);
            }
        } catch (\Exception $error) {
            // Preserve the subject lookup's 404 and any other HTTP boundary.
            if ($error instanceof HttpExceptionInterface) {
                throw $error;
            }
            report($error);
        }

        $headers = ['Cache-Control' => 'private, no-store'];
        if (!isset($summary)) {
            return response()->json(['message' => '讀取失敗'], 503, $headers);
        }

        $values = $summary[$kind];
        $values['cells'] = $this->reportCells($values, $kind === 'rating');
        return response()->json($values, 200, $headers);
    }

    private function lastSummaryCacheKey(array $subject, string $month): string
    {
        return 'ppb:report:last:v1:'.LegacyReader::SNAPSHOT_FORMAT.':'.$subject['type'].':'.$subject['id'].':'.$month;
    }

    private function summaryCacheKey(array $subject, string $month): string
    {
        return 'ppb:report:v1:'.LegacyReader::SNAPSHOT_FORMAT.':'.$subject['type'].':'.$subject['id'].':'.$month;
    }

    private function reportSummary(array $report, array $subject, string $month): array
    {
        $current = $report['current'];
        $level = $current['level'];
        $next = $report['recorded_next_level'] ?? ($level !== null ? ($current['next_rating']['rating_level'] ?? null) : null);
        $listLevel = $level ?? ($month === now('Asia/Taipei')->format('Y-m') ? ($subject['rating_level'] ?? null) : null);

        return [
            'schedule' => [
                'rating' => LegacyDisplayLabels::rating($listLevel),
                'score' => $current['view']['total_score'],
                'performance' => $current['performance'],
            ],
            'rating' => [
                'salary' => $current['salary'],
                'rating' => LegacyDisplayLabels::rating($level),
                'next_rating' => LegacyDisplayLabels::rating($next),
                'change' => $level === null || $next === null ? '無資料' : ['降級', '維持', '升級'][($next <=> $level) + 1],
                'scores' => array_reverse(array_column(array_column($report['history'], 'rating'), 'total_score')),
            ],
        ];
    }

    private function reportCells(array $summary, bool $isPay): array
    {
        if ($isPay) {
            return $summary['salary'] + [
                'rating' => $summary['rating'],
                'next_rating' => $summary['next_rating'],
                'change' => $summary['change'],
                ...array_combine(['score_0', 'score_1', 'score_2'], $summary['scores']),
            ];
        }

        $row = ['rating' => $summary['rating'], 'score' => $summary['score']];
        foreach (['month' => 'month', 'recent' => 'recent10', 'bonus' => 'bonus'] as $prefix => $period) {
            $stats = $summary['performance'][$period];
            $row[$prefix.'_rate'] = $stats['rate'];
            $row[$prefix.'_record'] = $stats['wins'] === null ? null : $stats['wins'].' - '.$stats['losses'];
        }
        return $row;
    }

    private const CSDA_FILTERS = [
        'all' => ['全部', null, null], 'person_all' => ['全部個人', 1, null], 'person_dealer' => ['經銷個人', 1, true],
        'person_normal' => ['非經銷個人', 1, false], 'group_all' => ['全部群組', 2, null],
        'group_dealer' => ['經銷群組', 2, true], 'group_normal' => ['非經銷群組', 2, false],
    ];

    /** Read-only copy of the legacy CSDA 用戶管理 list (owner-approved import); never writes to legacy. */
    public function csda(Request $request)
    {
        $user = $request->user('ppb');
        if (!Schema::hasTable('ppb_csda_users')) {
            return view('ppb.legacy-admin.csda', ['ready' => false, 'denied' => false]);
        }
        // Personal data: same gate as Line名稱 on the analyst list.
        if (!($user->hasPpbPermission('schedules.manage') && $user->hasPpbPermission('accounts.manage'))) {
            return view('ppb.legacy-admin.csda', ['ready' => false, 'denied' => true]);
        }
        $filter = array_key_exists((string) $request->query('filter'), self::CSDA_FILTERS) ? (string) $request->query('filter') : 'all';
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $apply = function ($query, string $key) {
            [, $type, $dealer] = self::CSDA_FILTERS[$key];
            if ($type !== null) {
                $query->where('type', $type);
            }
            if ($dealer !== null) {
                $query->where('is_dealer', $dealer);
            }
            return $query;
        };
        $all = DB::table('ppb_csda_users');
        $stats = [
            'all' => (clone $all)->count(), 'person' => (clone $all)->where('type', 1)->count(),
            'group' => (clone $all)->where('type', 2)->count(), 'active' => (clone $all)->where('is_active', true)->count(),
            'paid' => (clone $all)->where('is_paid', true)->count(),
        ];
        $counts = [];
        foreach (array_keys(self::CSDA_FILTERS) as $key) {
            $counts[$key] = $apply(DB::table('ppb_csda_users'), $key)->count();
        }
        $query = $apply(DB::table('ppb_csda_users'), $filter);
        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
            $query->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(line_id) LIKE ?', [$like]));
        }
        $users = $query->orderBy('legacy_id')->paginate(20, ['*'], 'page')->withQueryString();

        return response()->view('ppb.legacy-admin.csda', [
            'ready' => true, 'denied' => false, 'users' => $users, 'stats' => $stats, 'counts' => $counts,
            'filters' => self::CSDA_FILTERS, 'filter' => $filter, 'q' => $q,
        ])->header('Cache-Control', 'private, no-store');
    }
}
