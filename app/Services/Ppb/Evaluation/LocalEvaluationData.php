<?php

namespace App\Services\Ppb\Evaluation;

use App\Models\PpbAnalyst;
use App\Models\PpbEvaluation;
use App\Services\Ppb\LegacyDisplayLabels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** V2 copies and native records for schedule administration. */
class LocalEvaluationData
{
    public function __construct(private EvaluationSubjects $subjects) {}

    public function roster(): array
    {
        $maps = $this->subjects->mappings();
        $reverse = array_flip($maps);
        $roster = [];
        foreach (PpbAnalyst::whereIn('user_type', [0, 1])->orderBy('id')
            ->get(['id', 'name', 'display_name', 'rating_level', 'analyst_level']) as $analyst) {
            $legacy = $reverse[$analyst->id] ?? null;
            $key = $legacy === null ? 'v2:'.$analyst->id : 'legacy:'.$legacy;
            $roster[$key] = ['type' => $legacy === null ? 'v2' : 'legacy', 'id' => $legacy ?? $analyst->id,
                'native_id' => $analyst->id, 'label' => $analyst->display_name ?: $analyst->name,
                'rating_level' => $analyst->getRawOriginal('rating_level'), 'analyst_level' => $analyst->analyst_level];
        }
        $legacyIds = DB::table('ppb_legacy_pick_contents')->distinct()->orderBy('legacy_analyst_id')->pluck('legacy_analyst_id');
        foreach ($legacyIds as $id) {
            if (isset($maps[$id])) continue;
            $roster['legacy:'.$id] = ['type' => 'legacy', 'id' => (int) $id, 'native_id' => null,
                'label' => LegacyDisplayLabels::unmappedAnalyst((int) $id)];
        }
        return $roster;
    }

    public function ready(): bool
    {
        return Schema::hasTable('ppb_evaluation_attendance') && Schema::hasTable('ppb_evaluations')
            && Schema::hasTable('ppb_evaluation_videos') && Schema::hasTable('ppb_evaluation_ratings');
    }

    public function records(?CarbonImmutable $start = null, ?CarbonImmutable $end = null, bool $attendanceOnly = false)
    {
        $range = fn ($query) => $query->when($start, fn ($q) => $q->where('g.recommended_at', '>=', $start->utc()->toDateTimeString()))
            ->when($end, fn ($q) => $q->where('g.recommended_at', '<', $end->utc()->toDateTimeString()));
        $native = $range(DB::table('ppb_submissions as p')->join('ppb_games as g', 'g.id', '=', 'p.game_id')
            ->join('ppb_assignments as a', 'a.id', '=', 'p.assignment_id')
            ->whereColumn('a.game_id', 'p.game_id')->whereColumn('a.kind', 'p.kind')
            ->whereIn('p.kind', ['pick', 'bonus', 'long'])->whereNull('p.legacy_source_pick_id')
            ->whereIn('p.status', ['submitted', 'approved', 'changes_requested'])
            ->whereNotNull('p.submitted_at')->whereNotNull('p.submitted_analyst_id')
            ->when($attendanceOnly, fn ($q) => $q->whereColumn('a.original_analyst_id', '!=', 'p.submitted_analyst_id')))
            ->orderBy('p.id')->get(['p.id', 'p.game_id', 'p.kind', 'p.assignment_id', 'p.submitted_analyst_id',
                'p.status', 'p.submitted_at', ...($attendanceOnly ? [] : ['p.recommend_pick', 'p.analysis_text', 'p.content_body']),
                'a.original_analyst_id', 'g.recommended_at', 'g.game_type', 'g.game_number', 'g.market_type']);
        $legacy = $attendanceOnly ? collect() : $range(DB::table('ppb_legacy_pick_contents as p')
            ->join('ppb_games as g', 'g.id', '=', 'p.game_id')
            ->leftJoin('ppb_submissions as s', fn ($q) => $q->on('s.legacy_source_pick_id', '=', 'p.source_pick_id')
                ->on('s.game_id', '=', 'p.game_id')->on('s.kind', '=', 'p.kind')->where('s.status', 'legacy_imported')))
            ->orderBy('p.source_pick_id')->get(['p.*', 's.content_body', 's.assignment_id', 'g.recommended_at',
                'g.game_type', 'g.game_number', 'g.market_type']);
        $reverse = array_flip($this->subjects->mappings());
        $key = fn ($id) => isset($reverse[$id]) ? 'legacy:'.$reverse[$id] : 'v2:'.$id;
        // Some archives have no imported submission linked to the current slot.
        // Resolve those through the same verified analyst mapping used on the card.
        $legacyBySlot = $legacy->groupBy(fn ($row) => $row->game_id.':'.$row->kind.':'.$row->legacy_analyst_id);
        $activeBySlot = $legacy->isEmpty() ? collect() : DB::table('ppb_assignments')
            ->whereIntegerInRaw('game_id', $legacy->pluck('game_id')->unique()->all())
            ->where('status', 'active')->get(['id', 'game_id', 'kind', 'current_analyst_id'])
            ->groupBy(fn ($row) => $row->game_id.':'.$row->kind.':'.($reverse[$row->current_analyst_id] ?? ''));
        return $native->map(function ($row) use ($key, $attendanceOnly) {
            $substitute = $row->original_analyst_id != $row->submitted_analyst_id;
            return $this->record($row, 'v2', $row->id, $key($row->submitted_analyst_id),
                $substitute ? $key($row->original_analyst_id) : null, $substitute, 0,
                $attendanceOnly ? null : hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)));
        })->concat($legacy->map(function ($row) use ($legacyBySlot, $activeBySlot) {
            $slot = $row->game_id.':'.$row->kind.':'.$row->legacy_analyst_id;
            $active = $activeBySlot->get($slot, collect());
            if (!$active->contains('id', $row->assignment_id) && $active->count() === 1 && $legacyBySlot[$slot]->count() === 1) {
                $row->assignment_id = $active->first()->id;
            }
            $json = json_decode((string) $row->content_body, true);
            if (!($json['_ppb_legacy_pick_v1'] ?? false)) $json = [];
            return array_replace($this->record($row, 'legacy', $row->source_pick_id, 'legacy:'.$row->legacy_analyst_id,
                $row->legacy_is_substitute > 1 ? 'legacy:'.$row->legacy_is_substitute : null,
                $row->legacy_is_substitute != 0, $json['is_win'] ?? $row->legacy_is_win, $row->source_sha256),
                array_intersect_key($json, array_flip(LegacyScheduleCalculator::RATING_FIELDS)));
        }));
    }

    private function record(object $row, string $type, int $id, string $author, ?string $absent, bool $substitute, ?int $result, ?string $hash): array
    {
        return array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS, 0) + [
            '_source_type' => $type, '_source_id' => $id, '_author_key' => $author, '_absence_key' => $absent,
            '_is_substitution' => $substitute, '_schedule_key' => 'v2:'.$row->game_id, '_game_id' => (int) $row->game_id,
            '_assignment_id' => (int) $row->assignment_id, '_kind' => $row->kind,
            '_game_code' => ((int) $row->game_type === 1 ? 'G' : 'E').$row->game_number,
            '_manuscript_sha256' => $hash, 'market_type' => (int) $row->market_type,
            'recommended_time' => CarbonImmutable::parse($row->recommended_at, 'UTC')->tz('Asia/Taipei')->toDateTimeString(),
            'is_long' => (int) ($row->kind === 'long'), 'is_bonus' => (int) ($row->kind === 'bonus'), 'is_win' => $result,
        ];
    }

    public function corrections(?CarbonImmutable $start = null, ?CarbonImmutable $end = null)
    {
        return DB::table('ppb_evaluation_attendance as a')->join('ppb_games as g', 'g.id', '=', 'a.game_id')
            ->when($start, fn ($q) => $q->where('g.recommended_at', '>=', $start->utc()->toDateTimeString()))
            ->when($end, fn ($q) => $q->where('g.recommended_at', '<', $end->utc()->toDateTimeString()))
            ->orderBy('a.game_id')->orderBy('a.id')->get(['a.*', 'g.recommended_at']);
    }

    public function context(CarbonImmutable $date): array
    {
        $start = $date->subMonths(2);
        $end = $date->addMonth();
        $records = $this->records(null, $end)->groupBy('_author_key');
        $monthRecords = $this->records($start, $end, true);
        $corrections = $this->corrections($start, $end);
        $waived = $corrections->whereNull('attendance_status')->whereNotNull('waived_submission_id')
            ->keyBy(fn ($row) => $row->subject_type.':'.$row->subject_id.':'.$row->waived_submission_id);
        return [
            'records' => $records,
            'attendance' => collect((new EvaluationInputs)->attendanceRows($monthRecords, $corrections))
                ->groupBy(fn ($row) => $row['subject_type'].':'.$row['subject_id']),
            'waived' => $waived,
            'ratings' => DB::table('ppb_evaluation_ratings')->orderBy('id')->get()->groupBy(fn ($row) => $row->subject_type.':'.$row->subject_id),
            'evaluations' => PpbEvaluation::whereBetween('month', [$date->subMonths(3)->format('Y-m'), $date->format('Y-m')])
                ->orderBy('month')->get()->groupBy(fn ($row) => $row->subject_type.':'.$row->subject_id),
            'videos' => DB::table('ppb_evaluation_videos')->where('scheduled_at', '>=', $start->utc()->toDateTimeString())
                ->where('scheduled_at', '<', $end->utc()->toDateTimeString())->orderBy('id')->get(),
        ];
    }
}
