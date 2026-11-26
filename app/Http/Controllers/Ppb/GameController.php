<?php
namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Models\PpbAnalyst;
use App\Models\PpbGame;
use App\Services\Ppb\Evaluation\EvaluationInputs;
use App\Services\Ppb\Evaluation\EvaluationSubjects;
use App\Services\Ppb\Evaluation\LegacyPickRows;
use App\Services\Ppb\Evaluation\LocalEvaluationData;
use App\Services\Ppb\LegacyUnsettledCount;
use App\Services\Ppb\ScheduleManager;
use App\Services\Ppb\SchedulePickDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** PPB B1 | 2026-11-04 | Schedule UI; no WP posts are created here. */
class GameController extends Controller
{
    public function index(Request $request): View {
        $readOnly = $request->routeIs('ppb.games.readonly');
        $overview = $request->routeIs('ppb.schedule.overview');
        $matchNo=trim((string)$request->query('match_no',''));
        $matchDate=trim((string)$request->query('match_date',''));
        // PPB PG2 2026-11-05 | No default month filter: 2026-10-15+ and future schedules are visible.
        // An explicit ?month=YYYY-MM remains supported for bookmarked links.
        $month=trim((string)$request->query('month',''));
        if ($month!=='' && !preg_match('/^20\d\d-(0[1-9]|1[012])$/',$month)) $month='';
        // When searching an explicit date, use its month instead of the previous hidden filter.
        if (preg_match('/^20\d{2}-(0[1-9]|1[0-2])-([0-3]\d)$/',$matchDate) &&
            checkdate((int)substr($matchDate,5,2),(int)substr($matchDate,8,2),(int)substr($matchDate,0,4))) {
            $month=substr($matchDate,0,7);
        }
        $type=(string)$request->query('type','');
        $status=(string)$request->query('status','');
        $dateValid=$matchDate!=='' && preg_match('/^20\d{2}-(0[1-9]|1[0-2])-([0-3]\d)$/',$matchDate)
            && checkdate((int)substr($matchDate,5,2),(int)substr($matchDate,8,2),(int)substr($matchDate,0,4));
        $codeValid=$matchNo!=='' && preg_match('/^([GE])([1-9]\d{0,5})$/i',$matchNo,$codeParts);
        $games=PpbGame::query()->with(['assignments.currentAnalyst'])
            ->withCount(['assignments','submissions','publications'])
            ->when($month!=='',fn($q)=>$q->where('month_key',$month))
            ->when(in_array($type,['1','2'],true),fn($q)=>$q->where('game_type',(int)$type))
            ->when(in_array($status,['scheduled','completed','cancelled'],true),fn($q)=>$q->where('status',$status));
        if ($matchNo!=='') {
            if (!$codeValid) $games->whereRaw('1=0');
            else {
                $t=strtoupper($codeParts[1])==='G'?1:2;
                $n=(int)$codeParts[2];
                $games->where('game_type',$t)->where(function($q)use($n){$q->where('active_number',$n)->orWhere('game_number',$n);});
            }
        }
        if ($matchDate!=='') {
            if (!$dateValid) $games->whereRaw('1=0');
            else {
                $start=CarbonImmutable::createFromFormat('!Y-m-d H:i',$matchDate.' 00:00','Asia/Taipei')->utc();
                $end=$start->addDay();
                $games->where('recommended_at','>=',$start->format('Y-m-d H:i:s'))
                    ->where('recommended_at','<',$end->format('Y-m-d H:i:s'));
            }
        }
        // PPB PG8 | 2026-11-05 | Legacy future-first unlimited first page; past pages of 10.
        // The old shift(): p=1 is ALL games from the current local day at 00:00,
        // ordered ascending. History uses DESC 10-row batches, displayed ASC.
        // When there are no current or future games, history starts at p=1.
        // Filtered searches/bookmarked month/type/status retain the existing 10-row pager.
        $legacyList=config('ppb_legacy_listing');
        if (!is_array($legacyList) || ($legacyList['order'] ?? null)!==[
                ['recommended_at','desc'], ['game_type','desc'], ['game_number','desc']
            ] || (int)($legacyList['per_page']??0)!==10) {
            throw new \RuntimeException('Legacy schedule list config changed unexpectedly.');
        }
        $perPage=(int)$legacyList['per_page'];
        $sortAsc=static function($a,$b): int {
            return strcmp((string)$a->getRawOriginal('recommended_at'),(string)$b->getRawOriginal('recommended_at'))
                ?: ((int)$a->game_type <=> (int)$b->game_type)
                ?: ((int)$a->game_number <=> (int)$b->game_number)
                ?: ((int)$a->id <=> (int)$b->id);
        };
        $filtered=$matchNo!=='' || $matchDate!=='' || $month!==''
            || in_array($type,['1','2'],true)
            || in_array($status,['scheduled','completed','cancelled'],true);
        if ($filtered) {
            foreach ($legacyList['order'] as $pair) $games->orderBy($pair[0],$pair[1]);
            $games=$games->orderBy('id','desc')
                ->paginate($perPage,['*'],'p')->withQueryString();
            $games->setCollection($games->getCollection()->sort($sortAsc)->values());
        } else {
            // Source is UTC in V2; old ThinkPHP compares local start of TODAY.
            $todayUtc=CarbonImmutable::now('Asia/Taipei')->startOfDay()->utc()->format('Y-m-d H:i:s');
            $future=(clone $games)->where('recommended_at','>=',$todayUtc);
            $history=(clone $games)->where('recommended_at','<',$todayUtc);
            $futureItems=(clone $future)->reorder()->orderBy('recommended_at')
                ->orderBy('game_type')->orderBy('game_number')->orderBy('id')->get();
            $futureCount=$futureItems->count();
            $historyCount=(clone $history)->count();
            $total=(clone $games)->count();
            if ($futureCount+$historyCount!==$total) {
                throw new \RuntimeException('Schedule date partition would omit a row.');
            }
            $rawPage=$request->query('p','1');
            $page=is_scalar($rawPage)?max(1,(int)$rawPage):1;
            if ($page===1 && $futureCount>0) {
                $items=$futureItems;
            } else {
                $historyPage=$page-($futureCount>0 ? 1 : 0);
                $items=(clone $history)->reorder()->orderBy('recommended_at','desc')
                    ->orderBy('game_type','desc')->orderBy('game_number','desc')
                    ->orderBy('id','desc')->offset(($historyPage-1)*$perPage)->limit($perPage)
                    ->get()->sort($sortAsc)->values();
            }
            $virtualTotal=$futureCount>0 && $historyCount>0
                ? $perPage+$historyCount : $total;
            // An unlimited today+ page with no history is the only page.
            $paginatorSize=$historyCount===0 ? max($perPage,$futureCount) : $perPage;
            $games=new \Illuminate\Pagination\LengthAwarePaginator(
                $items,$virtualTotal,$paginatorSize,$page,
                ['path'=>$request->url(),'pageName'=>'p']
            );
            $games->appends($request->except('p'));
        }
        if ($overview) {
            $resultCount = $total ?? $games->total();
            return view('ppb.legacy-admin.overview', compact('games','matchNo','matchDate','resultCount'));
        }
        $ids=$games->getCollection()->pluck('reviewer_analyst_id')->filter()->unique()->values();
        $reviewers=$ids->isEmpty()?collect():PpbAnalyst::whereIn('id',$ids)->get()->keyBy('id');
        // PPB INS2 | Compute every VISIBLE card boundary once, without changing PG8 ordering.
        // Blocked boundaries still render a tiny, explanatory control; safe ones open the
        // existing create modal. All SQL below is SELECT; ScheduleManager rechecks on POST.
        $insertGaps=[];
        $visible=$games->getCollection()->values();
        if (!$readOnly && auth('ppb')->user()?->hasPpbPermission('schedules.manage')) {
            $scopes=[];
            $todayMonth=CarbonImmutable::now('Asia/Taipei')->format('Y-m');
            for ($i=1;$i<$visible->count();$i++) {
                $before=$visible->get($i-1);
                $after=$visible->get($i);
                $state=['safe'=>false,'reason'=>''];
                if ($before->month_key!==$after->month_key ||
                    (int)$before->game_type!==(int)$after->game_type) {
                    $state['reason']='前後賽事的月份或 G／E 類型不同，無法共用一個插入編號。';
                } elseif ($after->month_key<$todayMonth) {
                    $state['reason']='這個月份已經結束，新建賽事的推薦時間必須在未來。';
                } elseif ($before->status==='cancelled' || $after->status==='cancelled' ||
                    !$before->active_number || !$after->active_number) {
                    $state['reason']='此間隔含已取消或無現行編號的賽事，無法判定安全插入位置。';
                } elseif ((int)$before->active_number >= (int)$after->active_number) {
                    $state['reason']='目前卡片日期順序與 G／E 編號順序不同，請選擇同類型的連續編號。';
                } else {
                    $scopeKey=$after->month_key.':'.$after->game_type;
                    if (!isset($scopes[$scopeKey])) {
                        $byNumber=[];
                        // Includes hidden/off-page and WP-number-reserving cancelled rows.
                        $rows=PpbGame::query()->where('month_key',$after->month_key)
                            ->where('game_type',$after->game_type)
                            ->whereNotNull('active_number')->get();
                        foreach ($rows as $row) $byNumber[(int)$row->active_number]=$row;
                        $scopes[$scopeKey]=$byNumber;
                    }
                    $byNumber=$scopes[$scopeKey];
                    // A natural free number can be used after the previous game without
                    // touching the following WP-held game (important for MG4 imports).
                    $hasGap=(int)$after->active_number>(int)$before->active_number+1;
                    $position=$hasGap?'after':'before';
                    $anchor=$hasGap?$before:$after;
                    $target=$hasGap ? (int)$before->active_number+1 : (int)$after->active_number;
                    $cursor=$target;
                    $blocked='';
                    while (isset($byNumber[$cursor])) {
                        $moving=$byNumber[$cursor];
                        if ($moving->status==='cancelled' || $moving->hasVerifiedWp()) {
                            $blocked='此位置後方含已發布 WP、已取消且保留編號的賽事，不可順延。';
                            break;
                        }
                        if ($moving->hasUnresolvedWp()) {
                            $blocked='此位置後方有 WP 發布狀態待查的賽事，暫不能改號。';
                            break;
                        }
                        if ($moving->hasRenumberBlockingHistory()) {
                            $blocked='此位置後方有投稿、代班或歷史例外，不能安全順延。';
                            break;
                        }
                        $cursor++;
                    }
                    if ($blocked!=='') $state['reason']=$blocked;
                    else {
                        $state['safe']=true;
                        $state['position']=$position;
                        $state['anchor_id']=$anchor->id;
                    }
                }
                $insertGaps[(int)$after->id]=$state;
            }
        }
        // PPB CT6 2026-11-06 | Reuse legacy WP long URLs, never render post IDs as links.
        // Preserve all WP state and number-lock decisions in the existing backend.
        // One batched read per source, not per game. No content bodies or DB writes.
        $longArticleLinks=[];
        $visibleGameIds=$games->getCollection()->pluck('id')->map(static fn($id)=>(int)$id)->all();
        if ($visibleGameIds!==[]) {
            $safeLongUrl=static function ($raw): ?string {
                $url=trim((string)$raw);
                if ($url==='' || strlen($url)>500 || !filter_var($url,FILTER_VALIDATE_URL)) return null;
                if (preg_match('/[\\\\\x00-\x1f\x7f]/',$url)) return null;
                $parts=parse_url($url);
                if (!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https') return null;
                if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
                if (!in_array(strtolower((string)($parts['host']??'')),['csdatw.com','www.csdatw.com'],true)) return null;
                return $url;
            };
            $legacyUrls=[];
            foreach (DB::table('ppb_legacy_pick_contents')->whereIn('game_id',$visibleGameIds)
                ->where('kind','long')->orderBy('source_pick_id')
                ->get(['game_id','recommend_pick']) as $item) {
                $url=$safeLongUrl($item->recommend_pick);
                if ($url!==null) $legacyUrls[(int)$item->game_id][$url]=true;
            }
            foreach ($legacyUrls as $id=>$urls) {
                // Do not guess when one legacy game references multiple distinct URLs.
                if (count($urls)===1) $longArticleLinks[$id]=array_key_first($urls);
            }
            $verifiedUrls=[];
            foreach (DB::table('ppb_legacy_wp_articles')->whereIn('game_id',$visibleGameIds)
                ->orderBy('source_pick_id')->get(['game_id','wp_link']) as $article) {
                $url=$safeLongUrl($article->wp_link);
                if ($url!==null) $verifiedUrls[(int)$article->game_id][$url]=true;
            }
            foreach ($verifiedUrls as $id=>$urls) {
                if (count($urls)===1) $longArticleLinks[$id]=array_key_first($urls);
                else unset($longArticleLinks[$id]);
            }
        }
        $pickDisplay = app(\App\Services\Ppb\SchedulePickDisplay::class)->load($visible);
        return view('ppb.games-index',compact('pickDisplay','games','month','type','status','matchNo','matchDate','reviewers','insertGaps','longArticleLinks','readOnly'));
    }
    private function formData(?PpbGame $game): array {
        $analysts=PpbAnalyst::query()->where('status','active')->whereIn('user_type',[0,1])->orderBy('name')
            ->get(['id','name','display_name']);
        $reviewers=PpbAnalyst::query()->where('status','active')->whereIn('user_type',[1,2])->orderBy('name')
            ->get(['id','name','display_name']);
        $date = old('match_date') ?? request()->query('match_date');
        $date = is_string($date) && preg_match('/^20\d{2}-\d{2}-\d{2}$/D', $date)
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            ? CarbonImmutable::parse($date, 'Asia/Taipei')->startOfDay()
            : ($game?->recommended_at?->toImmutable()->tz('Asia/Taipei')->startOfDay() ?? CarbonImmutable::today('Asia/Taipei'));
        $fixtureId = (int) old('fixture_id', $game?->fixture_id);
        $fixtures = $this->fixturesForDate($date, $fixtureId);
        $absences = $this->absenceCounts();
        $selected=[];
        if($game) {
            $game->loadMissing('assignments');
            foreach ($game->assignments->where('status', 'active')->whereIn('kind',['pick','bonus','long']) as $a) {
                if($a->kind==='pick' && $a->slot_no) $selected['short'][$a->slot_no]=$a->current_analyst_id;
                elseif($a->kind==='bonus') $selected['bonus']=$a->current_analyst_id;
                elseif($a->kind==='long') $selected['long']=$a->current_analyst_id;
            }
        }
        $anchors=PpbGame::query()->whereNotNull('active_number')->where('status','!=','cancelled')
            ->where('month_key','>=',now('Asia/Taipei')->subMonths(2)->format('Y-m'))
            ->orderBy('game_type')->orderBy('game_number')->limit(1000)->get(['id','game_type','game_number','matchup_text','month_key']);
        return compact('analysts','reviewers','fixtures','selected','anchors','absences');
    }
    private function fixturesForDate(CarbonImmutable $date, int $selectedId)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('ppb_sport_fixtures')) return collect();
        $fixtures = DB::table('ppb_sport_fixtures')
            ->where(fn ($query) => $query->whereBetween('starts_at', [
                $date->subDay()->utc()->toDateTimeString(), $date->addDays(4)->utc()->toDateTimeString(),
            ])->orWhereNull('starts_at'))
            ->orderByRaw('CASE WHEN starts_at >= ? AND starts_at < ? THEN 0 WHEN starts_at IS NULL THEN 2 ELSE 1 END',
                [$date->utc()->toDateTimeString(), $date->addDay()->utc()->toDateTimeString()])
            ->orderBy('starts_at')->orderBy('sport')->orderBy('id')->limit(300)->get();
        if ($selectedId && !$fixtures->contains('id', $selectedId)) {
            $selected = DB::table('ppb_sport_fixtures')->where('id', $selectedId)->first();
            if ($selected) $fixtures->push($selected);
        }
        return $fixtures;
    }

    public function unsettledCount(LegacyUnsettledCount $legacy, SchedulePickDisplay $display): JsonResponse
    {
        try {
            $data = $legacy->snapshot();
        } catch (\Throwable $error) {
            report($error);
            $data = ['count' => $display->unsettledCount(), 'games' => []];
        }

        return response()->json($data, 200, ['Cache-Control' => 'private, no-store']);
    }

    private function absenceCounts(): array
    {
        try {
            return $this->computeAbsenceCounts();
        } catch (\Throwable $error) {
            // The absence suffix is advisory; never break the schedule form over it.
            report($error);

            return [];
        }
    }

    private function computeAbsenceCounts(): array
    {
        $start = CarbonImmutable::now('Asia/Taipei')->startOfMonth();
        $end = $start->addMonth();
        $maps = app(EvaluationSubjects::class)->mappings();
        $counts = [];
        if (Schema::hasTable('ppb_evaluation_attendance')) {
            $data = app(LocalEvaluationData::class);
            $inputs = app(EvaluationInputs::class);
            $native = collect($inputs->attendanceRows($data->records($start, $end, true), $data->corrections($start, $end)));
            $games = PpbGame::query()->whereIntegerInRaw('id', $native->pluck('gid')->unique()->all())
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('ppb_legacy_pick_contents as archive')
                    ->whereColumn('archive.game_id', 'ppb_games.id'))
                ->get(['id', 'notes'])->reject(fn ($game) => json_decode((string) $game->notes, true)['_ppb_legacy_game_v1'] ?? false)
                ->keyBy('id');
            $normalize = fn ($row) => $row['subject_type'] === 'v2' ? $row['subject_id'] : ($maps[$row['subject_id']] ?? null);
            $counts = $native->filter(fn ($row) => $games->has($row['gid']) && $normalize($row) !== null)
                ->keyBy(fn ($row) => $row['gid'].':'.$normalize($row))
                ->filter(fn ($row) => $row['attendance_status'] === 2)->countBy($normalize)->all();
        }

        try {
            $legacy = Cache::remember('ppb:legacy-pick-absences:v1:'.$start->format('Y-m'), 300, function () use ($start, $end) {
                $counts = [];
                foreach (app(LegacyPickRows::class)->select(['is_substitute'], [
                    ['column' => 'recommended_time', 'op' => '>=', 'value' => $start->toDateTimeString()],
                    ['column' => 'recommended_time', 'op' => '<=', 'value' => $end->subSecond()->toDateTimeString()],
                    ['column' => 'is_substitute', 'op' => '>', 'value' => 1],
                ]) as $row) {
                    $id = (int) $row['is_substitute'];
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }

                return $counts;
            });
        } catch (\Throwable $error) {
            report($error);

            return $counts;
        }

        foreach (array_intersect_key($legacy, $maps) as $legacyId => $count) {
            $id = $maps[$legacyId];
            $counts[$id] = ($counts[$id] ?? 0) + $count;
        }

        return $counts;
    }

    public function formOptions(Request $request): JsonResponse
    {
        $input = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'fixture_id' => ['nullable', 'integer', 'min:1']]);
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $input['date'], 'Asia/Taipei');
        $fixtures = $this->fixturesForDate($date, (int) ($input['fixture_id'] ?? 0))->map(fn ($fixture) => [
            'id' => $fixture->id, 'sport' => $fixture->sport,
            'label' => ($fixture->starts_at ? CarbonImmutable::parse($fixture->starts_at, 'UTC')->tz('Asia/Taipei')->format('m/d').'｜' : '')
                .$fixture->league.' '.$fixture->away_team.'@'.$fixture->home_team,
        ]);
        return response()->json(['fixtures' => $fixtures, 'absences' => (object) $this->absenceCounts()],
            200, ['Cache-Control' => 'private, no-store']);
    }

    /** Authenticated read-only lookup: insert anchor choices for any selected month. */
    public function anchors(Request $request): JsonResponse {
        $data=$request->validate([
            'month'=>['required','date_format:Y-m'],
            'type'=>['required',Rule::in(['1','2',1,2])],
        ]);
        $rows=PpbGame::query()->where('month_key',$data['month'])
            ->where('game_type',(int)$data['type'])->whereNotNull('active_number')
            ->where('status','!=','cancelled')->orderBy('active_number')
            ->limit(500)->get(['id','game_number','matchup_text']);
        return response()->json(['items'=>$rows]);
    }
    public function create(): View { return view('ppb.games-form',array_merge(['game'=>null],$this->formData(null))); }
    public function edit(PpbGame $game): View {
        abort_if($game->status==='cancelled',422,'已取消賽事只能查看歷史。');
        return view('ppb.games-form',array_merge(compact('game'),$this->formData($game)));
    }
    public function show(PpbGame $game): View {
        $game->load(['assignments.originalAnalyst:id,name,display_name',
            'assignments.currentAnalyst:id,name,display_name',
            'assignments.substitutions.fromAnalyst:id,name',
            'assignments.substitutions.toAnalyst:id,name']);
        $analysts=PpbAnalyst::where('status','active')->orderBy('name')->get(['id','name','display_name']);
        $events=DB::table('ppb_schedule_events')->where('game_id',$game->id)
            ->orderByDesc('id')->limit(20)->get();
        // PPB CT2 2026-11-05 | Legacy manuscript archive: read-only display.
        // This is not the WordPress publish state or editable submission workflow.
        $canSeeManuscripts=(bool) (auth('ppb')->user()?->hasPpbPermission('schedules.manage') ?? false);
        $legacyPicks=$canSeeManuscripts
            ? DB::table('ppb_legacy_pick_contents')->where('game_id',$game->id)
                ->orderByRaw("CASE kind WHEN 'pick' THEN 1 WHEN 'bonus' THEN 2 ELSE 3 END")
                ->orderBy('source_pick_id')->get()
            : collect();
        // PPB CT4 | 2026-11-05 | Only independently REST-verified historical long WP posts.
        // No anonymous article body exposure; admin schedules.manage only.
        $wpVerifiedByPick=$canSeeManuscripts
            ? DB::table('ppb_legacy_wp_articles')->where('game_id',$game->id)->get()->keyBy('source_pick_id')
            : collect();
        $archivedPickIds=[];
        foreach (DB::table('ppb_schedule_events')->where('game_id',$game->id)
            ->where('event','legacy_archived_pick')->pluck('detail_json') as $detail) {
            $decoded=json_decode((string)$detail,true);
            if (is_array($decoded) && isset($decoded['source_pick_id'])) {
                $archivedPickIds[(int)$decoded['source_pick_id']]=true;
            }
        }
        return view('ppb.games-show',compact('game','analysts','events','legacyPicks','archivedPickIds','canSeeManuscripts','wpVerifiedByPick'));
    }
    private function validateInputs(Request $request,?PpbGame $game=null): array {
        $v=$request->validate([
            'game_type'=>['required',Rule::in(['1','2',1,2])],
            'match_date'=>['required','date_format:Y-m-d'],
            'match_time'=>['required',Rule::in(['11:00','16:00'])],
            'matchup_mode'=>['required',Rule::in(['manual','fixture'])],
            'fixture_id'=>['nullable','integer','exists:ppb_sport_fixtures,id'],
            'manual_league'=>['nullable','string','max:50'],
            'manual_matchup'=>['nullable','string','max:255'],
            'play_type'=>['required',Rule::in(['1','2','3','4','5',1,2,3,4,5])],
            'play_value'=>['nullable','string','max:160'],
            'market_type'=>['nullable',Rule::in(['1','2',1,2])],
            'special_play'=>['nullable','string','max:160'],
            'manual_play'=>['nullable','string','max:160'],
            'reviewer_analyst_id'=>['nullable','integer',Rule::exists('ppb_analysts','id')->where('status','active')->whereIn('user_type',[1,2])],
            'has_long_article'=>['nullable','boolean'],
            'short'=>['nullable','array'],
            'short.*'=>['nullable','integer',Rule::exists('ppb_analysts','id')->where('status','active')->whereIn('user_type',[0,1])],
            'bonus_id'=>['nullable','integer',Rule::exists('ppb_analysts','id')->where('status','active')->whereIn('user_type',[0,1])],
            'long_id'=>['nullable','integer',Rule::exists('ppb_analysts','id')->where('status','active')->whereIn('user_type',[0,1])],
            'starts_local'=>['nullable','date_format:Y-m-d\TH:i'],
            'position'=>['nullable',Rule::in(['append','before','after'])],
            'anchor_id'=>['nullable','integer','exists:ppb_games,id'],
            'notes'=>['nullable','string','max:3000'],
            'lottery_no'=>['nullable','string','max:255'],
        ]);
        $dt=CarbonImmutable::createFromFormat('!Y-m-d H:i', $v['match_date'].' '.$v['match_time'],'Asia/Taipei');
        if (!$dt || $dt->format('Y-m-d')!==$v['match_date'] || $dt->format('H:i')!==$v['match_time']) {
            throw ValidationException::withMessages(['match_date'=>'請選擇有效日期與時段。']);
        }
        if (!$game || ($game->recommended_at && !$game->recommended_at->equalTo($dt->utc()))) {
            if ($dt->lessThanOrEqualTo(CarbonImmutable::now('Asia/Taipei'))) {
                throw ValidationException::withMessages(['match_date'=>'推薦時間必須晚於目前時間。']);
            }
        }
        $short=[];
        foreach(range(1,5) as $n) $short[$n]=!empty($v['short'][$n])?(int)$v['short'][$n]:null;
        $ids=array_values(array_filter($short));
        if(count($ids)!==count(array_unique($ids))) {
            throw ValidationException::withMessages(['short'=>'同一場的短文五個名額不得重複指派同一位分析師。']);
        }
        $hasLong=$request->boolean('has_long_article');
        $slots=['short'=>$short,'bonus'=>!empty($v['bonus_id'])?(int)$v['bonus_id']:null,
            'long'=>$hasLong && !empty($v['long_id'])?(int)$v['long_id']:null];
        $mode=$v['matchup_mode'];
        if($mode==='fixture') {
            if(empty($v['fixture_id'])) throw ValidationException::withMessages(['fixture_id'=>'請選擇既有賽事。']);
            $fixture=DB::table('ppb_sport_fixtures')->where('id',$v['fixture_id'])->first();
            if(!$fixture) throw ValidationException::withMessages(['fixture_id'=>'對戰賽程已不存在。']);
            $league=$fixture->league;
            $matchup=$fixture->matchup_text;
            $sport=mb_substr((string)$fixture->sport,0,20);
            $home=$fixture->home_team ?: '—'; $away=$fixture->away_team ?: '—';
            if(mb_strlen((string)$league)>50) throw ValidationException::withMessages(['fixture_id'=>'聯賽名稱超出可儲存長度。']);
            $fixtureId=$fixture->id;
        } else {
            $league=trim((string)($v['manual_league']??''));
            $matchup=trim((string)($v['manual_matchup']??''));
            if($league==='' && $matchup==='') throw ValidationException::withMessages(['manual_matchup'=>'聯賽或對戰組合至少填寫一項。']);
            [$away,$home]=array_pad(array_map('trim',explode('@',$matchup,2)),2,'—');
            $home=$home!==''?$home:'—'; $away=$away!==''?$away:'—';
            $sport='other'; $fixtureId=null;
        }
        if(mb_strlen(trim(($league!==''?$league.' ':'').$matchup))>255) {
            throw ValidationException::withMessages(['manual_matchup'=>'對戰組合內容過長。']);
        }
        $play = $this->playFields($v);
        $start=null;
        if(!empty($v['starts_local'])) {
            $start=CarbonImmutable::createFromFormat('!Y-m-d\TH:i',$v['starts_local'],'Asia/Taipei');
            if(!$start || $start->format('Y-m-d\TH:i')!==$v['starts_local']) {
                throw ValidationException::withMessages(['starts_local'=>'實際開賽時間格式錯誤。']);
            }
        }
        $fields=[
            'month_key'=>$dt->format('Y-m'),'game_type'=>(int)$v['game_type'],
            'recommended_at'=>$dt->utc()->format('Y-m-d H:i:s'),
            'starts_at'=>$start?->utc()->format('Y-m-d H:i:s'),
            'matchup_mode'=>$mode,'matchup_text'=>trim(($league!==''?$league.' ':'').$matchup),
            'fixture_id'=>$fixtureId,'league'=>$league?:null,'sport'=>$sport,
            'home_team'=>$home,'away_team'=>$away,
            ...$play,
            'reviewer_analyst_id'=>!empty($v['reviewer_analyst_id'])?(int)$v['reviewer_analyst_id']:null,
            'has_long_article'=>$hasLong,'notes'=>$this->scheduleNotes($v, $game),
        ];
        return [$fields,$slots,$v];
    }
    private function scheduleNotes(array $data, ?PpbGame $game): ?string
    {
        $stored = json_decode((string) $game?->notes, true);
        $notes = $data['notes'] ?? null;
        $lottery = array_key_exists('lottery_no', $data) ? $data['lottery_no'] : $game?->lotteryNumber();
        if (!is_array($stored) && $lottery === null) return array_key_exists('notes', $data) ? $notes : $game?->notes;

        $metadata = is_array($stored) ? $stored : [];
        if (!is_array($stored) && !array_key_exists('notes', $data)) {
            $notes = $game?->notes;
        }
        if (array_key_exists('notes', $data) || $notes !== null) {
            if ($notes === null) unset($metadata['notes']);
            else $metadata['notes'] = $notes;
        }
        if ($lottery === null) unset($metadata['lottery_no']);
        else $metadata['lottery_no'] = $lottery;

        return $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;
    }

    private function playFields(array $v): array {
        $playType=(int)$v['play_type'];
        if(in_array($playType,[1,3],true) && trim((string)($v['play_value']??''))==='') {
            throw ValidationException::withMessages(['play_value'=>'請填寫讓分數值或大小分基準。']);
        }
        if(in_array($playType,[1,2,3],true) && empty($v['market_type'])) {
            throw ValidationException::withMessages(['market_type'=>'請選擇兩路盤或三路盤。']);
        }
        if($playType===4 && trim((string)($v['special_play']??''))==='') {
            throw ValidationException::withMessages(['special_play'=>'請填寫特殊玩法。']);
        }
        if($playType===5 && trim((string)($v['manual_play']??''))==='') {
            throw ValidationException::withMessages(['manual_play'=>'請填寫手動玩法。']);
        }
        $playVal=match($playType) {
            4=>trim((string)($v['special_play']??'')),
            5=>trim((string)($v['manual_play']??'')),
            default=>trim((string)($v['play_value']??'')),
        };
        return ['play_type' => $playType, 'play_value' => $playVal ?: null,
            'market_type' => in_array($playType, [1, 2, 3], true) ? (int) $v['market_type'] : null];
    }

    public function store(Request $request,ScheduleManager $manager): RedirectResponse {
        [$data,$slots,$v]=$this->validateInputs($request);
        $position=$v['position']??'append';
        $game=$manager->create($data,$slots,$position,!empty($v['anchor_id'])?(int)$v['anchor_id']:null);
        // PPB INS1: insertion returns to PG8's today-forward first page and locates the new card.
        // Ordinary append keeps the established show-page navigation unchanged.
        if ($position!=='append') {
            return redirect(route('ppb.games.index',['p'=>1]).'#ppb-game-'.$game->id)
                ->with('status','賽事 '.$game->display_code.' 已插入，已定位至班表。');
        }
        return redirect()->route('ppb.games.show',$game)->with('status','賽事 '.$game->display_code.' 已建立。');
    }
    public function update(Request $request,PpbGame $game,ScheduleManager $manager): RedirectResponse {
        $version=$request->validate(['revision_token'=>['required','regex:/^[a-f0-9]{64}$/D']])['revision_token'];
        if ($game->hasVerifiedWp() || $game->hasUnresolvedWp()) {
            $analystRule = Rule::exists('ppb_analysts', 'id')->where('status', 'active')->whereIn('user_type', [0, 1]);
            $fields = $request->validate([
                'play_type' => ['required', 'integer', 'between:1,5'],
                'play_value' => ['nullable', 'string', 'max:160'],
                'market_type' => ['nullable', 'integer', Rule::in([1, 2])],
                'special_play' => ['nullable', 'string', 'max:160'],
                'manual_play' => ['nullable', 'string', 'max:160'],
                'short' => ['nullable', 'array:1,2,3,4,5'],
                'short.*' => ['nullable', 'integer', $analystRule],
                'bonus_id' => ['nullable', 'integer', $analystRule],
                'long_id' => ['nullable', 'integer', $analystRule],
            ]);
            $slots = ['short' => array_map('intval', $fields['short'] ?? []), 'bonus' => (int) ($fields['bonus_id'] ?? 0),
                'long' => (int) ($fields['long_id'] ?? 0)];
            $manager->update($game, $this->playFields($fields), $slots, $version, wpMetadataOnly: true);
        } else {
            [$data,$slots]=$this->validateInputs($request,$game);
            $manager->update($game,$data,$slots,$version);
        }
        return redirect()->route('ppb.games.show',$game)->with('status','賽事資訊與分析師指派已更新。');
    }
    public function destroy(Request $request,PpbGame $game,ScheduleManager $manager): RedirectResponse {
        $version=$request->validate(['revision_token'=>['required','regex:/^[a-f0-9]{64}$/D']])['revision_token'];
        $manager->delete($game,$version);
        return redirect()->route('ppb.games.index')->with('status','賽事已刪除，可安全補位的編號已調整。');
    }
    public function cancel(Request $request,PpbGame $game,ScheduleManager $manager): RedirectResponse {
        $data=$request->validate(['reason'=>['required','string','max:255'],'revision_token'=>['required','regex:/^[a-f0-9]{64}$/D']]);
        $manager->cancel($game,$data['reason'],$data['revision_token']);
        return redirect()->route('ppb.games.show',$game)->with('status','賽事已取消，投稿與歷史紀錄保留。');
    }
}
