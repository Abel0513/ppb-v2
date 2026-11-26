<?php

namespace App\Services\Ppb;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SchedulePickDisplay
{
    public function load(Collection $games): array
    {
        if ($games->isEmpty()) return [];
        $ids = $games->pluck('id');
        $archives = DB::table('ppb_legacy_pick_contents')->whereIn('game_id', $ids)->get()->groupBy('game_id');
        $submissions = DB::table('ppb_submissions')->whereIn('game_id', $ids)
            ->where('status', 'legacy_imported')->orderByDesc('id')->get();
        $byAssignment = $submissions->groupBy('assignment_id');
        $legacyByNative = array_flip(app(Evaluation\EvaluationSubjects::class)->mappings());
        $events = DB::table('ppb_schedule_events')->whereIn('game_id', $ids)
            ->where('event', 'legacy_archived_pick')->orderBy('id')->get();
        $assigned = $archived = $unlinked = [];
        foreach ($games as $game) {
            $picks = $archives->get($game->id, collect());
            foreach ($game->assignments->where('status', 'active') as $assignment) {
                $analyst = $assignment->current_analyst_id;
                $kind = $assignment->kind;
                $submission = $byAssignment->get($assignment->id, collect())->first(fn ($row) =>
                    $row->game_id == $game->id && $row->kind === $kind && $row->submitted_analyst_id == $analyst);
                $candidates = $picks->where('kind', $kind);
                $byAnalyst = $candidates->where('legacy_analyst_id', $legacyByNative[$analyst] ?? -1);
                $archive = $submission?->legacy_source_pick_id
                    ? $candidates->firstWhere('source_pick_id', $submission->legacy_source_pick_id)
                    : ($byAnalyst->count() === 1 ? $byAnalyst->first() : null);
                $assigned[$assignment->id] = $this->preview($submission?->content_body, $archive);
            }
        }
        foreach ($events as $event) {
            $detail = json_decode((string) $event->detail_json, true);
            $slot = (int) ($detail['slot_no'] ?? 0);
            if (!($detail['historical_only'] ?? false) || ($detail['kind'] ?? null) !== 'pick'
                || $slot < 1 || $slot > 5 || (int) ($detail['analyst_id'] ?? 0) < 1) continue;
            $archive = $archives->get($event->game_id, collect())->where('kind', 'pick')
                ->firstWhere('source_pick_id', $detail['source_pick_id'] ?? null);
            $archived[$event->game_id][$slot] = $detail + [
                'preview' => $this->preview(null, $archive, $detail['source_row'] ?? []),
            ];
        }
        foreach ($submissions->whereNull('assignment_id') as $submission) {
            $body = json_decode((string) $submission->content_body, true);
            $slot = $body['legacy_slot_no'] ?? null;
            $label = $body['legacy_analyst_label'] ?? null;
            if (!($body['_ppb_legacy_pick_v1'] ?? false) || !is_int($slot) || !is_string($label)) continue;
            $archive = $archives->get($submission->game_id, collect())
                ->firstWhere('source_pick_id', $submission->legacy_source_pick_id);
            if (!$archive || $archive->kind !== $submission->kind) continue;
            $unlinked[$submission->game_id][$submission->kind][$slot] = [
                'name' => $label, 'preview' => $this->preview($submission->content_body, $archive),
            ];
        }
        return ['assigned' => $assigned, 'archived' => $archived, 'unlinked' => $unlinked];
    }

    private function preview(?string $body, ?object $archive, array $fallback = []): array
    {
        $json = json_decode((string) $body, true);
        if (!($json['_ppb_legacy_pick_v1'] ?? false)) $json = [];
        $pick = [];
        foreach (['recommend_pick', 'short_analysis', 'is_win'] as $field) {
            $value = $json[$field] ?? null;
            $column = $field === 'is_win' ? 'legacy_is_win' : $field;
            $pick[$field] = (is_string($value) || is_int($value)) && trim((string) $value) !== ''
                ? $value : ($archive?->$column ?? $fallback[$field] ?? null);
        }
        return $pick;
    }

    public function unsettledCount(): ?int
    {
        try {
            return $this->countUnsettledGames();
        } catch (\Illuminate\Database\QueryException $error) {
            report($error);
            return null;
        }
    }

    private function countUnsettledGames(): int
    {
        $scheduled = \App\Models\PpbGame::where('status', 'scheduled')->count();
        $games = \App\Models\PpbGame::whereNotIn('status', ['scheduled', 'cancelled'])
            ->whereHas('assignments', fn ($q) => $q->where('status', 'active')->whereIn('kind', ['pick', 'bonus']))
            ->with(['assignments' => fn ($q) => $q->where('status', 'active')->whereIn('kind', ['pick', 'bonus'])]);
        $unsettled = 0;
        $games->chunkById(300, function ($batch) use (&$unsettled) {
            $picks = $this->load($batch)['assigned'] ?? [];
            foreach ($batch as $game) {
                if ($game->assignments->contains(fn ($assignment) => !in_array((int) ($picks[$assignment->id]['is_win'] ?? 0), [1, 2], true))) {
                    $unsettled++;
                }
            }
        });
        return $scheduled + $unsettled;
    }
}
