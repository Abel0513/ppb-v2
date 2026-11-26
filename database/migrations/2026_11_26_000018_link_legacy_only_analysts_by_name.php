<?php

use App\Services\Ppb\Evaluation\EvaluationSubjects;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * The old analyst list shows legacy #6/#7/#18/#22/#49 once each. In V2 they had no
 * legacy-imported submissions, so EvaluationSubjects could not derive a mapping and
 * each appeared twice (unmapped legacy row + V2-only row). This records an explicit
 * legacy -> V2 analyst link (unique exact name match only) and moves evaluation
 * rows keyed to the V2-only subject onto the legacy subject. V2 analyst records,
 * assignments and submissions keep their IDs, so nothing is orphaned.
 * Idempotent; output and logs contain counts only.
 */
return new class extends Migration {
    /** legacy analyst id => legacy name (verified in the 2026-11-26 legacy roster export). */
    public const TARGETS = [6 => '葉秋', 7 => '洛老師', 18 => '潔西', 22 => 'BONBON', 49 => '元'];

    public function up(): void
    {
        $this->createTable();
        $this->apply(database_path('data/legacy-import-20261126/analysts.json'));
    }

    public function createTable(): void
    {
        if (!Schema::hasTable('ppb_legacy_analyst_links')) {
            Schema::create('ppb_legacy_analyst_links', function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_analyst_id')->primary();
                $table->unsignedBigInteger('analyst_id')->unique();
                $table->string('source', 64);
                $table->timestamps();
            });
        }
    }

    public function apply(string $rosterPath): array
    {
        $roster = [];
        foreach (json_decode((string) file_get_contents($rosterPath), true, 512, JSON_THROW_ON_ERROR) as $row) {
            $roster[(int) $row['id']] = trim((string) $row['name']);
        }

        $counts = DB::transaction(function () use ($roster) {
            $counts = [
                'links' => ['linked' => 0, 'already' => 0, 'mapped_by_evidence' => 0, 'roster_mismatch' => 0, 'no_match' => 0, 'ambiguous' => 0],
                'remapped' => ['evaluations' => 0, 'ratings' => 0, 'attendance' => 0, 'video_guests' => 0],
                'remap_conflicts' => ['evaluations' => 0, 'attendance' => 0],
            ];
            $subjects = app(EvaluationSubjects::class);

            foreach (self::TARGETS as $legacyId => $name) {
                if (($roster[$legacyId] ?? null) !== $name) {
                    ++$counts['links']['roster_mismatch'];
                    continue;
                }
                $existing = DB::table('ppb_legacy_analyst_links')->where('legacy_analyst_id', $legacyId)->value('analyst_id');
                if ($existing !== null) {
                    ++$counts['links']['already'];
                    $this->remap((int) $existing, $legacyId, $counts);
                    continue;
                }
                $mapping = $subjects->mappings();
                if (isset($mapping[$legacyId])) {
                    ++$counts['links']['mapped_by_evidence'];
                    continue;
                }
                $taken = array_flip(array_map('intval', $mapping));
                $candidates = DB::table('ppb_analysts')->whereIn('user_type', [0, 1])->get(['id', 'name', 'display_name'])
                    ->filter(fn ($a) => trim((string) $a->name) === $name || trim((string) $a->display_name) === $name);
                $free = $candidates->reject(fn ($a) => isset($taken[(int) $a->id]));
                if ($candidates->count() > 1 || $free->count() > 1) {
                    ++$counts['links']['ambiguous'];
                    continue;
                }
                if ($free->count() !== 1) {
                    ++$counts['links']['no_match'];
                    continue;
                }
                $nativeId = (int) $free->first()->id;
                DB::table('ppb_legacy_analyst_links')->insert([
                    'legacy_analyst_id' => $legacyId, 'analyst_id' => $nativeId, 'source' => 'exact_name_20261126',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                ++$counts['links']['linked'];
                $this->remap($nativeId, $legacyId, $counts);
            }

            return $counts;
        }, 3);

        Log::info('PPB legacy analyst link merge 2026-11-26', $counts);
        echo 'PPB legacy analyst link merge 2026-11-26 '.json_encode($counts, JSON_THROW_ON_ERROR).PHP_EOL;

        return $counts;
    }

    /** Move rows keyed to subject v2:<native> onto legacy:<legacy>; never overwrite an existing legacy row. */
    private function remap(int $nativeId, int $legacyId, array &$counts): void
    {
        foreach (['evaluations' => ['ppb_evaluations', 'month'], 'attendance' => ['ppb_evaluation_attendance', 'game_id']] as $key => [$table, $column]) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->where('subject_type', 'v2')->where('subject_id', $nativeId)->get(['id', $column]) as $row) {
                $clash = DB::table($table)->where('subject_type', 'legacy')->where('subject_id', $legacyId)->where($column, $row->{$column})->exists();
                if ($clash) {
                    ++$counts['remap_conflicts'][$key];
                    continue;
                }
                DB::table($table)->where('id', $row->id)->update(['subject_type' => 'legacy', 'subject_id' => $legacyId, 'updated_at' => now()]);
                ++$counts['remapped'][$key];
            }
        }
        if (Schema::hasTable('ppb_evaluation_ratings')) {
            $counts['remapped']['ratings'] += DB::table('ppb_evaluation_ratings')->where('subject_type', 'v2')->where('subject_id', $nativeId)
                ->update(['subject_type' => 'legacy', 'subject_id' => $legacyId, 'updated_at' => now()]);
        }
        if (Schema::hasTable('ppb_evaluation_videos')) {
            foreach (['guest_1', 'guest_2', 'guest_3'] as $guest) {
                $counts['remapped']['video_guests'] += DB::table('ppb_evaluation_videos')->where($guest, 'v2:'.$nativeId)
                    ->update([$guest => 'legacy:'.$legacyId, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('PPB_LEGACY_ANALYST_LINK_MERGE_REQUIRES_REVIEWED_RECOVERY');
    }
};
