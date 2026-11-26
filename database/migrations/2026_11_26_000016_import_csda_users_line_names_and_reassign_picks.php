<?php

use App\Services\Ppb\Evaluation\EvaluationSubjects;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * Owner-approved 2026-11-26 (t27s9): CSDA users and analyst LINE names may be
 * stored in V2; picks 3988/4008 follow the legacy author. Idempotent, keyed by
 * legacy IDs. Output and logs contain counts and IDs only, never personal data.
 */
return new class extends Migration {
    /** legacy pick id => legacy analyst id now shown by the legacy system. */
    public const REASSIGN = [3988 => 15, 4008 => 11];

    public function up(): void
    {
        $this->createTables();
        $this->importSnapshot(database_path('data/legacy-import-20261126b'));
    }

    public function createTables(): void
    {
        if (!Schema::hasTable('ppb_csda_users')) {
            Schema::create('ppb_csda_users', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('legacy_id')->unique();
                $table->string('name', 191);
                $table->unsignedTinyInteger('type'); // 1 個人, 2 群組
                $table->string('line_id', 191)->nullable();
                $table->string('remark', 500)->nullable();
                $table->boolean('is_listen')->default(false);
                $table->boolean('is_active')->default(false);
                $table->boolean('is_paid')->default(false);
                $table->boolean('is_dealer')->default(false);
                $table->dateTime('legacy_created_at')->nullable();
                $table->dateTime('legacy_updated_at')->nullable();
                $table->timestamps();
                $table->index(['type', 'is_dealer']);
            });
        }
        if (!Schema::hasTable('ppb_legacy_analyst_profiles')) {
            Schema::create('ppb_legacy_analyst_profiles', function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_analyst_id')->primary();
                $table->string('line_name', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function importSnapshot(string $directory): array
    {
        $manifest = json_decode($this->read($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $data = [];
        foreach (['csda_users.json', 'analyst_line_names.json'] as $file) {
            $bytes = $this->read($directory.'/'.$file);
            if (!hash_equals($manifest[$file]['sha256'] ?? '', hash('sha256', $bytes))) {
                throw new RuntimeException("Legacy snapshot SHA-256 mismatch for {$file}");
            }
            $rows = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (!array_is_list($rows) || count($rows) !== ($manifest[$file]['rows'] ?? null)) {
                throw new RuntimeException("Legacy snapshot row count mismatch for {$file}");
            }
            $data[$file] = $rows;
        }

        $counts = DB::transaction(function () use ($data) {
            $counts = [
                'csda_users' => ['inserted' => 0, 'updated' => 0, 'unchanged' => 0],
                'legacy_line_names' => ['inserted' => 0, 'updated' => 0, 'unchanged' => 0],
                'analyst_line_names' => ['filled' => 0, 'kept_existing' => 0, 'unmapped' => 0],
                'picks' => ['reassigned' => 0, 'already' => 0, 'missing' => 0, 'assignment_moved' => 0, 'assignment_unlinked' => 0],
            ];
            // Resolve legacy -> V2 analysts before any lineage changes.
            $mapping = app(EvaluationSubjects::class)->mappings();

            foreach ($data['csda_users.json'] as $user) {
                $values = [
                    'name' => (string) $user['name'], 'type' => (int) $user['type'],
                    'line_id' => $user['line_id'] === '' ? null : $user['line_id'], 'remark' => $user['remark'],
                    'is_listen' => (int) $user['is_listen'] === 1, 'is_active' => (int) $user['is_active'] === 1,
                    'is_paid' => (int) $user['is_paid'] === 1, 'is_dealer' => (int) $user['is_dealer'] === 1,
                    'legacy_created_at' => $user['created_at'], 'legacy_updated_at' => $user['updated_at'],
                ];
                $this->upsert('ppb_csda_users', ['legacy_id' => (int) $user['id']], $values, $counts['csda_users']);
            }

            foreach ($data['analyst_line_names.json'] as $row) {
                $legacyId = (int) $row['legacy_analyst_id'];
                $name = trim((string) $row['line_name']);
                $this->upsert('ppb_legacy_analyst_profiles', ['legacy_analyst_id' => $legacyId], ['line_name' => $name], $counts['legacy_line_names']);
                $native = $mapping[$legacyId] ?? null;
                if ($native === null) {
                    ++$counts['analyst_line_names']['unmapped'];
                    continue;
                }
                $current = DB::table('ppb_analysts')->where('id', $native)->lockForUpdate()->value('line_name');
                if (trim((string) $current) === '') {
                    DB::table('ppb_analysts')->where('id', $native)->update(['line_name' => $name, 'updated_at' => now()]);
                    ++$counts['analyst_line_names']['filled'];
                } else {
                    ++$counts['analyst_line_names']['kept_existing']; // V2 edits win.
                }
            }

            foreach (self::REASSIGN as $pickId => $legacyAnalyst) {
                $this->reassign($pickId, $legacyAnalyst, $mapping[$legacyAnalyst] ?? null, $counts['picks']);
            }

            return $counts;
        }, 3);

        Log::info('PPB legacy CSDA/LINE/reassign 2026-11-26 imported', $counts);
        echo 'PPB legacy CSDA/LINE/reassign 2026-11-26 '.json_encode($counts, JSON_THROW_ON_ERROR).PHP_EOL;

        return $counts;
    }

    private function reassign(int $pickId, int $legacyAnalyst, ?int $native, array &$count): void
    {
        $archive = DB::table('ppb_legacy_pick_contents')->where('source_pick_id', $pickId)->lockForUpdate()->first();
        if (!$archive) {
            ++$count['missing'];
            return;
        }
        $submission = DB::table('ppb_submissions')->where('legacy_source_pick_id', $pickId)->lockForUpdate()->first();
        $assignment = $submission?->assignment_id
            ? DB::table('ppb_assignments')->where('id', $submission->assignment_id)->lockForUpdate()->first() : null;

        $done = (int) $archive->legacy_analyst_id === $legacyAnalyst
            && (!$submission || ($submission->submitted_analyst_id === null ? $native === null : (int) $submission->submitted_analyst_id === $native))
            && (!$assignment || (int) $assignment->current_analyst_id === $native);
        if ($done) {
            ++$count['already'];
            return;
        }

        if ((int) $archive->legacy_analyst_id !== $legacyAnalyst) {
            $game = DB::table('ppb_games')->where('id', $archive->game_id)->value('notes');
            $schedule = json_decode((string) $game, true)['legacy_source_schedule_id'] ?? null;
            DB::table('ppb_legacy_pick_contents')->where('source_pick_id', $pickId)->update([
                'legacy_analyst_id' => $legacyAnalyst,
                'source_sha256' => hash('sha256', json_encode([
                    'source_pick_id' => $pickId, 'legacy_analyst_id' => $legacyAnalyst, 'kind' => $archive->kind,
                    'legacy_game_code' => $archive->legacy_game_code, 'recommend_pick' => $archive->recommend_pick,
                    'short_analysis' => $archive->short_analysis, 'legacy_is_substitute' => (int) $archive->legacy_is_substitute,
                    'legacy_is_win' => $archive->legacy_is_win === null ? null : (int) $archive->legacy_is_win,
                    'legacy_recommended_time' => (string) $archive->legacy_recommended_time,
                    'legacy_source_schedule_id' => $schedule,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ]);
        }

        if ($submission) {
            $changes = ['submitted_analyst_id' => $native, 'updated_at' => now()];
            if ($assignment && $native !== null) {
                $hasOther = DB::table('ppb_submissions')->where('assignment_id', $assignment->id)->where('id', '!=', $submission->id)->exists();
                if (!$hasOther) {
                    // Legacy shows the new author as the regular (non-substitute) analyst of this slot.
                    $originTaken = DB::table('ppb_assignments')->where('game_id', $assignment->game_id)
                        ->where('kind', $assignment->kind)->where('original_analyst_id', $native)
                        ->where('id', '!=', $assignment->id)->exists();
                    DB::table('ppb_assignments')->where('id', $assignment->id)->update(
                        ['current_analyst_id' => $native, 'updated_at' => now()]
                        + ($originTaken ? [] : ['original_analyst_id' => $native])
                    );
                    ++$count['assignment_moved'];
                }
            } elseif ($assignment) {
                // No verified V2 analyst: detach and keep the legacy slot/label for display.
                $body = json_decode((string) $submission->content_body, true) ?: [];
                $changes['assignment_id'] = null;
                $changes['content_body'] = json_encode($body + ['_ppb_legacy_pick_v1' => true, 'legacy_slot_no' => (int) $assignment->slot_no],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                ++$count['assignment_unlinked'];
            }
            DB::table('ppb_submissions')->where('id', $submission->id)->update($changes);
        }
        ++$count['reassigned'];
    }

    private function upsert(string $table, array $key, array $values, array &$count): void
    {
        $old = DB::table($table)->where($key)->lockForUpdate()->first();
        if (!$old) {
            DB::table($table)->insert($key + $values + ['created_at' => now(), 'updated_at' => now()]);
            ++$count['inserted'];
            return;
        }
        $changes = array_filter($values, function ($value, $field) use ($old) {
            $current = $old->$field;
            if (is_bool($value)) {
                return (bool) (int) $current !== $value;
            }
            return $value === null ? $current !== null : (string) $current !== (string) $value;
        }, ARRAY_FILTER_USE_BOTH);
        if ($changes) {
            DB::table($table)->where($key)->update($changes + ['updated_at' => now()]);
            ++$count['updated'];
        } else {
            ++$count['unchanged'];
        }
    }

    private function read(string $path): string
    {
        $bytes = is_readable($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new RuntimeException("Legacy snapshot file unavailable: {$path}");
        }
        return $bytes;
    }

    public function down(): void
    {
        throw new RuntimeException('PPB_LEGACY_CSDA_IMPORT_REQUIRES_REVIEWED_RECOVERY');
    }
};
