<?php

use App\Services\Ppb\Evaluation\EvaluationSubjects;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * Follow-up to 000016 (owner decision t27s9). The schedule card shows the slot's
 * assignment analyst and finds the pick through that analyst's legacy ID, so the
 * slot itself must follow the reassigned legacy author. Also fills Line名稱 for
 * V2-only analysts by a unique exact name match against the legacy roster.
 * Idempotent; output and logs contain counts only.
 */
return new class extends Migration {
    /** legacy pick id => [previous legacy author, new legacy author, legacy slot]. */
    public const SLOTS = [3988 => [24, 15, 4], 4008 => [24, 11, 5]];

    public function up(): void
    {
        $this->apply(database_path('data/legacy-import-20261126/analysts.json'));
    }

    public function apply(string $rosterPath): array
    {
        $roster = json_decode((string) file_get_contents($rosterPath), true, 512, JSON_THROW_ON_ERROR);

        $counts = DB::transaction(function () use ($roster) {
            $counts = [
                'slots' => ['moved' => 0, 'already' => 0, 'missing' => 0, 'unmapped_target' => 0, 'changed_in_v2' => 0],
                'line_names_by_name' => ['filled' => 0, 'kept_existing' => 0, 'no_match' => 0],
            ];
            $mapping = app(EvaluationSubjects::class)->mappings();

            foreach (self::SLOTS as $pickId => [$from, $to, $slot]) {
                $this->moveSlot($pickId, $mapping[$from] ?? null, $mapping[$to] ?? null, $slot, $counts['slots']);
            }

            // Line名稱 for V2 analysts without verified legacy lineage.
            $legacyByName = [];
            foreach ($roster as $row) {
                $legacyByName[trim((string) $row['name'])][] = (int) $row['id'];
            }
            $lines = DB::table('ppb_legacy_analyst_profiles')->pluck('line_name', 'legacy_analyst_id');
            $mappedNative = array_flip($mapping);
            $natives = DB::table('ppb_analysts')->get(['id', 'name', 'display_name', 'line_name']);
            $nameCount = [];
            foreach ($natives as $analyst) {
                foreach (array_unique(array_filter([trim((string) $analyst->name), trim((string) $analyst->display_name)])) as $name) {
                    $nameCount[$name] = ($nameCount[$name] ?? 0) + 1;
                }
            }
            foreach ($natives as $analyst) {
                if (isset($mappedNative[(int) $analyst->id])) {
                    continue; // Mapped analysts were handled by 000016.
                }
                $candidates = [];
                foreach (array_unique(array_filter([trim((string) $analyst->display_name), trim((string) $analyst->name)])) as $name) {
                    if (count($legacyByName[$name] ?? []) === 1 && ($nameCount[$name] ?? 0) === 1) {
                        $candidates[$legacyByName[$name][0]] = true;
                    }
                }
                $legacyId = count($candidates) === 1 ? array_key_first($candidates) : null;
                $line = $legacyId !== null ? trim((string) ($lines[$legacyId] ?? '')) : '';
                if ($line === '') {
                    ++$counts['line_names_by_name']['no_match'];
                } elseif (trim((string) $analyst->line_name) !== '') {
                    ++$counts['line_names_by_name']['kept_existing'];
                } else {
                    DB::table('ppb_analysts')->where('id', $analyst->id)->update(['line_name' => $line, 'updated_at' => now()]);
                    ++$counts['line_names_by_name']['filled'];
                }
            }

            return $counts;
        }, 3);

        Log::info('PPB legacy slot/LINE follow-up 2026-11-26', $counts);
        echo 'PPB legacy slot/LINE follow-up 2026-11-26 '.json_encode($counts, JSON_THROW_ON_ERROR).PHP_EOL;

        return $counts;
    }

    private function moveSlot(int $pickId, ?int $fromNative, ?int $toNative, int $slot, array &$count): void
    {
        $archive = DB::table('ppb_legacy_pick_contents')->where('source_pick_id', $pickId)->first();
        if (!$archive) {
            ++$count['missing'];
            return;
        }
        $active = DB::table('ppb_assignments')->where('game_id', $archive->game_id)->where('kind', $archive->kind)
            ->where('status', 'active')->lockForUpdate()->get();
        if ($toNative !== null && $active->contains(fn ($row) => (int) $row->slot_no === $slot && (int) $row->current_analyst_id === $toNative)) {
            ++$count['already'];
            return;
        }
        if ($toNative === null) {
            ++$count['unmapped_target'];
            return;
        }
        // The slot that still shows the previous author; fall back to that author's unique slot.
        $target = $active->first(fn ($row) => (int) $row->slot_no === $slot && $fromNative !== null && (int) $row->current_analyst_id === $fromNative);
        if (!$target && $fromNative !== null) {
            $byAuthor = $active->filter(fn ($row) => (int) $row->current_analyst_id === $fromNative);
            $target = $byAuthor->count() === 1 ? $byAuthor->first() : null;
        }
        if (!$target) {
            ++$count['changed_in_v2']; // Never overwrite a slot that V2 users already changed.
            return;
        }
        $originTaken = DB::table('ppb_assignments')->where('game_id', $target->game_id)->where('kind', $target->kind)
            ->where('original_analyst_id', $toNative)->where('id', '!=', $target->id)->exists();
        DB::table('ppb_assignments')->where('id', $target->id)->update(
            ['current_analyst_id' => $toNative, 'updated_at' => now()] + ($originTaken ? [] : ['original_analyst_id' => $toNative])
        );
        ++$count['moved'];
    }

    public function down(): void
    {
        throw new RuntimeException('PPB_LEGACY_SLOT_FOLLOW_UP_REQUIRES_REVIEWED_RECOVERY');
    }
};
