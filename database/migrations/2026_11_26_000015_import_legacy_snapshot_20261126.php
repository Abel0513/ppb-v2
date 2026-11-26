<?php

use App\Services\Ppb\Evaluation\EvaluationSubjects;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration {
    private const TABLES = [
        'ppb_games', 'ppb_assignments', 'ppb_submissions', 'ppb_legacy_pick_contents',
        'ppb_evaluation_videos', 'ppb_sport_fixtures',
    ];

    private const SKIP = 409;
    /** @var array<string, list<string>> */
    private array $conflicts = [];

    public function up(): void
    {
        $this->importSnapshot(database_path('data/legacy-import-20261126'));
    }

    public function importSnapshot(string $directory): void
    {
        $manifest = $this->readJson($directory.'/manifest.json');
        $snapshot = [];
        foreach (['games', 'picks', 'videos', 'fixtures', 'analysts'] as $name) {
            $file = $name.'.json';
            $bytes = $this->readFile($directory.'/'.$file);
            $entry = $manifest[$file] ?? [];
            if (!hash_equals($entry['sha256'] ?? '', hash('sha256', $bytes))) {
                throw new RuntimeException("Legacy snapshot SHA-256 mismatch for {$file}");
            }
            $rows = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($rows) || !array_is_list($rows) || count($rows) !== ($entry['rows'] ?? null)
                || count(array_unique(array_column($rows, 'id'))) !== count($rows)) {
                throw new RuntimeException("Legacy snapshot row count or source ID mismatch for {$file}");
            }
            $snapshot[$name] = $rows;
        }

        $counts = DB::transaction(function () use ($snapshot) {
            $this->conflicts = [];
            $counts = array_fill_keys(self::TABLES, ['inserted' => 0, 'updated' => 0, 'skipped' => 0]);
            $this->lockScheduleScopes($snapshot['games']);
            [$mapping, $nativeNames] = $this->analystMapping($snapshot['analysts']);
            $fixtureIds = $this->importFixtures($snapshot['fixtures'], $counts['ppb_sport_fixtures']);

            $picks = collect($snapshot['picks'])->groupBy('game_id');
            $analystNames = array_column($snapshot['analysts'], 'name', 'id');
            $games = [];
            $existingIds = DB::table('ppb_games')->where('notes', 'like', '%"legacy_source_schedule_id"%')
                ->get(['id', 'notes'])->groupBy(fn ($game) =>
                    json_decode($game->notes, true)['legacy_source_schedule_id'] ?? 0);
            foreach ($snapshot['games'] as $game) {
                $games[$game['id']] = true;
                $gamePicks = $picks->get($game['id'], collect());
                $fixtureKey = substr($game['recommended_time'], 0, 10).'|'.$game['matchup'];
                try {
                    $gameId = $this->importGame($game, $gamePicks, $fixtureIds[$fixtureKey] ?? null,
                        $mapping, $existingIds->get($game['id'], collect()), $counts['ppb_games']);
                } catch (RuntimeException $conflict) {
                    $this->skipOrFail($conflict, 'games', $counts['ppb_games']);
                    foreach ($gamePicks as $pick) {
                        $this->conflicts['picks_of_skipped_games'][] = (string) $pick['id'];
                        ++$counts['ppb_legacy_pick_contents']['skipped'];
                    }
                    continue;
                }
                foreach ($gamePicks as $pick) {
                    $this->importPick($pick, $game, $gameId, $mapping, $analystNames[$pick['analyst_id']], $counts);
                }
            }
            if (array_diff_key($picks->all(), $games)) {
                throw new RuntimeException('Legacy snapshot contains picks without a source game');
            }

            $names = array_column($snapshot['analysts'], 'id', 'name');
            $legacyByNative = array_flip($mapping);
            foreach ($snapshot['videos'] as $video) {
                try {
                    $this->importVideo($video, $names, $nativeNames, $legacyByNative, $counts['ppb_evaluation_videos']);
                } catch (RuntimeException $conflict) {
                    $this->skipOrFail($conflict, 'videos', $counts['ppb_evaluation_videos']);
                }
            }
            return $counts;
        }, 3);

        $report = array_map(fn ($items) => ['count' => count($items), 'items' => array_slice($items, 0, 50)], $this->conflicts);
        Log::info('PPB legacy snapshot 2026-11-26 imported', ['counts' => $counts, 'conflicts' => $report]);
        echo 'PPB legacy snapshot 2026-11-26 '.json_encode($counts, JSON_THROW_ON_ERROR).PHP_EOL;
        echo 'PPB legacy snapshot 2026-11-26 conflicts '.json_encode(array_map(fn ($items) => count($items), $this->conflicts), JSON_THROW_ON_ERROR).PHP_EOL;
    }

    /** Conflicts that cannot corrupt data are reported and the source row is skipped. */
    private function skip(string $message): RuntimeException
    {
        return new RuntimeException($message, self::SKIP);
    }

    private function skipOrFail(RuntimeException $conflict, string $kind, array &$count): void
    {
        if ($conflict->getCode() !== self::SKIP) {
            throw $conflict;
        }
        $this->conflicts[$kind][] = $conflict->getMessage();
        ++$count['skipped'];
    }

    private function lockScheduleScopes(array $games): void
    {
        $scopes = [];
        foreach ($games as $game) {
            $month = substr($game['recommended_time'], 0, 7);
            $type = $game['game_type'];
            $scopes[$month.':'.$type] = ['month_key' => $month, 'game_type' => $type];
        }
        ksort($scopes);
        foreach ($scopes as $scope) {
            DB::table('ppb_schedule_scopes')->insertOrIgnore($scope);
            DB::table('ppb_schedule_scopes')->where($scope)->lockForUpdate()->first();
        }
    }

    private function analystMapping(array $analysts): array
    {
        // mappings() derives its evidence entirely from existing local source links.
        $mapping = app(EvaluationSubjects::class)->mappings();
        $nativeNames = [];
        foreach (DB::table('ppb_analysts')->get(['id', 'name', 'display_name']) as $row) {
            foreach (array_unique([$row->name, $row->display_name]) as $name) {
                if ($name !== null && $name !== '') {
                    $nativeNames[$name][] = (int) $row->id;
                }
            }
        }

        // Conflicting historical pairs cannot be repaired by guessing from a display name.
        $linked = DB::table('ppb_submissions as s')
            ->join('ppb_legacy_pick_contents as p', 'p.source_pick_id', '=', 's.legacy_source_pick_id')
            ->where('s.status', 'legacy_imported')->whereNotNull('s.submitted_analyst_id')
            ->whereColumn('s.game_id', 'p.game_id')->whereColumn('s.kind', 'p.kind')
            ->distinct()->get(['p.legacy_analyst_id', 's.submitted_analyst_id']);
        $linkedLegacy = $linked->pluck('legacy_analyst_id')->flip();
        $linkedNative = $linked->pluck('submitted_analyst_id')->flip();

        $candidates = [];
        foreach ($analysts as $analyst) {
            $matches = $nativeNames[$analyst['name']] ?? [];
            if (count($matches) === 1 && !$linkedLegacy->has($analyst['id']) && !$linkedNative->has($matches[0])) {
                $candidates[$matches[0]][] = $analyst['id'];
            }
        }
        foreach ($candidates as $native => $legacyIds) {
            if (count($legacyIds) === 1) {
                $mapping[$legacyIds[0]] = $native;
            }
        }
        return [$mapping, $nativeNames];
    }

    private function importGame(array $source, $picks, ?object $fixture, array $mapping, $existingIds, array &$count): int
    {
        $time = $this->utc($source['recommended_time']);
        $month = substr($source['recommended_time'], 0, 7);
        $type = $source['game_type'];
        $number = $source['game_number'];
        $scope = DB::table('ppb_games')->where('month_key', $month)->where('game_type', $type);
        $matches = (clone $scope)->where('game_number', $number)->where('recommended_at', $time)
            ->lockForUpdate()->get()->filter(fn ($game) =>
                json_decode((string) $game->notes, true)['_ppb_legacy_game_v1'] ?? false);
        $legacyId = $source['id'];
        if ($matches->count() > 1) {
            throw $this->skip("Ambiguous imported V2 game for legacy schedule {$legacyId}");
        }
        $old = $matches->first();
        if ($existingIds->isNotEmpty() && ($existingIds->count() !== 1 || $existingIds->first()->id !== $old?->id)) {
            throw $this->skip("Legacy schedule {$legacyId} no longer matches its linked V2 game");
        }
        $play = [
            'play_type' => $source['play_type'], 'play_value' => $source['play_value'],
            'market_type' => $source['market_type'],
        ];
        $reviewer = $mapping[$source['reviewer_id']] ?? null;

        if ($old) {
            $notes = json_decode($old->notes, true, 512, JSON_THROW_ON_ERROR);
            if (isset($notes['legacy_source_schedule_id']) && (int) $notes['legacy_source_schedule_id'] !== $legacyId) {
                throw $this->skip("V2 game {$old->id} is linked to a different legacy schedule");
            }
            $merged = $notes + ['legacy_source_schedule_id' => $legacyId];
            if (trim((string) ($notes['lottery_no'] ?? '')) === '' && $source['lottery_no'] !== null) {
                $merged['lottery_no'] = $source['lottery_no'];
            }
            $changes = [];
            if ($old->play_type === null && trim((string) $old->play_value) === '') {
                $changes = array_filter($play, fn ($value, $key) => $value !== null && $old->$key === null, ARRAY_FILTER_USE_BOTH);
                if ($source['play_type'] === null && $source['prediction'] !== null) {
                    $merged['legacy_prediction'] ??= $source['prediction'];
                }
            } elseif ((int) $old->play_type === $source['play_type']) {
                $effectiveValue = $old->play_value;
                if (trim((string) $effectiveValue) === '' && $source['play_value'] !== null) {
                    $changes['play_value'] = $effectiveValue = $source['play_value'];
                }
                if ($effectiveValue === $source['play_value'] && $old->market_type === null && $source['market_type'] !== null) {
                    $changes['market_type'] = $source['market_type'];
                }
            }
            if ($old->reviewer_analyst_id === null && $reviewer !== null) {
                $changes['reviewer_analyst_id'] = $reviewer;
            }
            if ($merged !== $notes) {
                $changes['notes'] = $this->json($merged);
            }
            $this->save('ppb_games', ['id' => $old->id], $old, $changes, $count);
            return (int) $old->id;
        }

        if ((clone $scope)->where('active_number', $number)->lockForUpdate()->first()) {
            $code = ($type === 1 ? 'G' : 'E').$number;
            throw $this->skip("Legacy schedule {$legacyId} conflicts with occupied V2 slot {$month} {$code}");
        }

        $cancelled = $source['cancelled'];
        $settled = $picks->whereIn('kind', ['short', 'bonus']);
        $completed = $settled->isNotEmpty() && $settled->every(fn ($pick) => in_array($pick['is_win'], [1, 2], true));
        $notes = array_filter([
            '_ppb_legacy_game_v1' => true,
            'legacy_source_schedule_id' => $legacyId,
            'lottery_no' => $source['lottery_no'],
            'legacy_prediction' => $source['prediction'],
        ], fn ($value) => $value !== null);
        // CT2 reused the legacy schedule ID when available. A colliding V2
        // primary key keeps its own identity; notes retain the legacy link.
        $identity = DB::table('ppb_games')->where('id', $legacyId)->lockForUpdate()->first() ? [] : ['id' => $legacyId];
        ++$count['inserted'];
        return (int) DB::table('ppb_games')->insertGetId($identity + $play + [
            'month_key' => $month, 'game_type' => $type, 'game_number' => $number,
            'active_number' => $cancelled ? null : $number,
            'recommended_at' => $time, 'starts_at' => $fixture?->starts_at,
            'status' => $cancelled ? 'cancelled' : ($completed ? 'completed' : 'scheduled'),
            'notes' => $this->json($notes), 'reviewer_analyst_id' => $reviewer,
            'has_long_article' => $source['has_long'], 'matchup_text' => $source['matchup'],
            'matchup_mode' => $fixture ? 'fixture' : 'manual', 'fixture_id' => $fixture?->id,
            'sport' => $fixture?->sport ?? 'other', 'league' => $source['league'],
            'home_team' => $source['home'] ?? '—', 'away_team' => $source['away'] ?? '—',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function importPick(array $pick, array $game, int $gameId, array $mapping, string $analystName, array &$counts): void
    {
        $kind = $pick['kind'] === 'short' ? 'pick' : $pick['kind'];
        $source = ['source_pick_id' => $pick['id']];
        $archive = DB::table('ppb_legacy_pick_contents')->where($source)->lockForUpdate()->first();
        $submissionKey = ['legacy_source_pick_id' => $pick['id']];
        $submission = DB::table('ppb_submissions')->where($submissionKey)->lockForUpdate()->first();
        $analystId = $pick['analyst_id'];
        if (($archive && ((int) $archive->game_id !== $gameId || $archive->kind !== $kind))
            || ($submission && ((int) $submission->game_id !== $gameId || $submission->kind !== $kind
                || $submission->status !== 'legacy_imported'))) {
            // A different game or kind would move the pick; keep the existing V2 rows untouched.
            $this->conflicts['picks_skipped_lineage'][] = (string) $pick['id'];
            ++$counts['ppb_legacy_pick_contents']['skipped'];
            return;
        }
        if ($archive && (int) $archive->legacy_analyst_id !== $analystId) {
            // Same legacy pick ID, game and kind, but the legacy author was reassigned
            // after the earlier import. Keep the existing V2 attribution, links and
            // numbers; refresh only the text and record the reconciliation.
            $text = ['recommend_pick' => $pick['long_url'] ?? $pick['recommend'],
                'short_analysis' => $kind === 'long' ? ($archive->short_analysis ?? $pick['analysis']) : $pick['analysis']];
            $text = array_filter($text, fn ($value) => $value !== null);
            if ($this->changes($archive, $text)) {
                $kept = array_merge((array) $archive, $text);
                $text['source_sha256'] = hash('sha256', $this->json($source + [
                    'legacy_analyst_id' => (int) $kept['legacy_analyst_id'], 'kind' => $kept['kind'],
                    'legacy_game_code' => $kept['legacy_game_code'], 'recommend_pick' => $kept['recommend_pick'],
                    'short_analysis' => $kept['short_analysis'], 'legacy_is_substitute' => (int) $kept['legacy_is_substitute'],
                    'legacy_is_win' => $kept['legacy_is_win'] === null ? null : (int) $kept['legacy_is_win'],
                    'legacy_recommended_time' => $kept['legacy_recommended_time'],
                    'legacy_source_schedule_id' => $game['id'],
                ]));
            }
            $this->save('ppb_legacy_pick_contents', $source, $archive, $text, $counts['ppb_legacy_pick_contents'], false);
            $this->conflicts['picks_reconciled_author'][] = $pick['id'].':'.$archive->legacy_analyst_id.'->'.$analystId;
            ++$counts['ppb_assignments']['skipped'];
            ++$counts['ppb_submissions']['skipped'];
            return;
        }

        // CT6 stores the source URL in recommend_pick. Verified CT4 archives
        // and WP IDs need independent evidence unavailable in this export.
        $values = [
            'game_id' => $gameId, 'legacy_analyst_id' => $analystId, 'kind' => $kind,
            'legacy_game_code' => ($game['game_type'] === 1 ? 'G' : 'E').$game['game_number'],
            'recommend_pick' => $pick['long_url'] ?? $pick['recommend'],
            'short_analysis' => $kind === 'long' ? ($archive?->short_analysis ?? $pick['analysis']) : $pick['analysis'],
            'legacy_is_substitute' => $pick['is_substitute'], 'legacy_is_win' => $pick['is_win'],
            'legacy_recommended_time' => $game['recommended_time'],
        ];
        $archiveChanged = !$archive || $this->changes($archive, $values) !== [];
        if ($archiveChanged) {
            $sourceContent = array_diff_key($values, ['game_id' => true]);
            $values['source_sha256'] = hash('sha256', $this->json($source + $sourceContent + [
                'legacy_source_schedule_id' => $game['id'],
            ]));
        }
        $this->save('ppb_legacy_pick_contents', $source, $archive, $values, $counts['ppb_legacy_pick_contents'], false);

        $native = $mapping[$analystId] ?? null;
        if ($submission) {
            ++$counts['ppb_assignments']['skipped'];
            $body = json_decode((string) $submission->content_body, true);
            $changes = [];
            if ($archiveChanged && $archive && ($body['_ppb_legacy_pick_v1'] ?? false)) {
                foreach (['recommend_pick' => 'recommend_pick', 'short_analysis' => 'short_analysis', 'is_win' => 'legacy_is_win'] as $key => $column) {
                    if (array_key_exists($key, $body) && $this->sameValue($body[$key], $archive->$column)
                        && !$this->sameValue($body[$key], $values[$column])) {
                        $body[$key] = $values[$column];
                        $changes['content_body'] = $this->json($body);
                    }
                }
            }
            $this->save('ppb_submissions', $submissionKey, $submission, $changes, $counts['ppb_submissions']);
            return;
        }

        if ($archive) {
            // CT2 also kept archives without linked submissions, including
            // historical slot exceptions. Retain those original dispositions.
            ++$counts['ppb_assignments']['skipped'];
            ++$counts['ppb_submissions']['skipped'];
            return;
        }

        $original = $pick['is_substitute'] > 1 ? ($mapping[$pick['is_substitute']] ?? $native) : $native;
        $assignmentId = $this->assignment($gameId, $kind, $pick['slot'], $original, $native, $counts['ppb_assignments']);
        $body = ['_ppb_legacy_pick_v1' => true];
        if ($assignmentId === null) {
            $body += ['legacy_slot_no' => $pick['slot'], 'legacy_analyst_label' => $analystName];
        }
        $this->save('ppb_submissions', $submissionKey, null, [
            'game_id' => $gameId, 'kind' => $kind, 'status' => 'legacy_imported',
            'assignment_id' => $assignmentId, 'submitted_analyst_id' => $native,
            'content_body' => $this->json($body),
        ], $counts['ppb_submissions']);
    }

    private function assignment(int $gameId, string $kind, int $slot, ?int $original, ?int $native, array &$count): ?int
    {
        ++$count['skipped'];
        // Production assignments require real V2 analyst IDs. The archive and
        // nullable submission link retain unmapped authors and conflicting slots.
        if ($native === null) {
            return null;
        }
        $key = ['game_id' => $gameId, 'kind' => $kind, 'slot_no' => $slot];
        $existing = DB::table('ppb_assignments')->where($key)->lockForUpdate()->first();
        if ($existing) {
            $hasSubmission = DB::table('ppb_submissions')->where('assignment_id', $existing->id)->exists();
            return (int) $existing->current_analyst_id === $native && $existing->status === 'active' && !$hasSubmission
                ? (int) $existing->id : null;
        }
        if (DB::table('ppb_assignments')->where('game_id', $gameId)->where('kind', $kind)
            ->where('original_analyst_id', $original)->exists()) {
            return null;
        }
        --$count['skipped'];
        ++$count['inserted'];
        return (int) DB::table('ppb_assignments')->insertGetId($key + [
            'original_analyst_id' => $original, 'current_analyst_id' => $native, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function importFixtures(array $fixtures, array &$count): array
    {
        $byMatch = [];
        foreach ($fixtures as $fixture) {
            $key = ['source' => 'pay_sport_schedule', 'source_id' => (string) $fixture['id']];
            try {
                $old = $this->uniqueRow('ppb_sport_fixtures', $key);
            } catch (RuntimeException $conflict) {
                $this->skipOrFail($conflict, 'fixtures', $count);
                continue;
            }
            $matchup = trim($fixture['league'].' '.$fixture['away'].'@'.$fixture['home']);
            $values = [
                'sport' => $fixture['sport'], 'league' => $fixture['league'],
                'away_team' => $fixture['away'], 'home_team' => $fixture['home'],
                'matchup_text' => $fixture['away'].'@'.$fixture['home'], 'starts_at' => $this->utc($fixture['game_time']),
            ];
            $id = $this->save('ppb_sport_fixtures', $key, $old, $values, $count);
            $byMatch[substr($fixture['game_time'], 0, 10).'|'.$matchup][] = (object) (['id' => $id] + $values);
        }
        return array_map(fn ($rows) => count($rows) === 1 ? $rows[0] : null, $byMatch);
    }

    private function importVideo(array $source, array $names, array $nativeNames, array $legacyByNative, array &$count): void
    {
        // The legacy numeric ID is not a V2 primary key. Match one weekly
        // slot on the date and week label, both available in this schema.
        $week = array_search($source['week_label'], [1 => '第一周', '第二周', '第三周', '第四周', '第五周'], true);
        if ($week === false) {
            throw $this->skip("Unknown week label for legacy video {$source['id']}");
        }
        $key = ['scheduled_at' => $this->utc($source['date'].' 00:00:00'), 'week_no' => $week];
        $old = $this->uniqueRow('ppb_evaluation_videos', $key);
        $values = ['host_name' => $source['host'], 'editor_name' => $source['editor'], 'sport_label' => $source['sport_tag']];
        for ($i = 1; $i <= 3; $i++) {
            $name = $source["guest{$i}"];
            $legacy = $names[$name] ?? null;
            $native = $nativeNames[$name] ?? [];
            $values["guest_{$i}"] = match (true) {
                $legacy !== null => 'legacy:'.$legacy,
                count($native) === 1 && isset($legacyByNative[$native[0]]) => 'legacy:'.$legacyByNative[$native[0]],
                count($native) === 1 => 'v2:'.$native[0],
                default => null,
            };
        }
        if ($old && $this->changes($old, $values)) {
            $values['revision'] = (int) $old->revision + 1;
        }
        $this->save('ppb_evaluation_videos', $key, $old, $values, $count);
    }

    private function uniqueRow(string $table, array $key): ?object
    {
        $rows = DB::table($table)->where($key)->lockForUpdate()->limit(2)->get();
        if ($rows->count() > 1) {
            throw $this->skip("Ambiguous legacy snapshot match in {$table}: ".$this->json($key));
        }
        return $rows->first();
    }

    private function save(string $table, array $key, ?object $old, array $values, array &$count, bool $timestamps = true): int
    {
        $query = DB::table($table)->where($key);
        if (!$old) {
            ++$count['inserted'];
            $row = $key + $values + ['created_at' => now()];
            if ($timestamps) {
                return (int) DB::table($table)->insertGetId($row + ['updated_at' => now()]);
            }
            DB::table($table)->insert($row);
            return (int) reset($key);
        }
        $changes = $this->changes($old, $values);
        if ($changes) {
            ++$count['updated'];
            $query->update($changes + ($timestamps ? ['updated_at' => now()] : []));
        } else {
            ++$count['skipped'];
        }
        return (int) ($old->id ?? reset($key));
    }

    private function changes(object $old, array $values): array
    {
        return array_filter($values, fn ($value, $field) => !$this->sameValue($value, $old->$field), ARRAY_FILTER_USE_BOTH);
    }

    private function sameValue($left, $right): bool
    {
        // SQL drivers can return integer columns as numeric strings.
        return $left === $right || (is_int($left) && (string) $left === $right)
            || (is_int($right) && $left === (string) $right);
    }

    private function utc(string $time): string
    {
        return CarbonImmutable::parse($time, 'Asia/Taipei')->utc()->toDateTimeString();
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function readJson(string $path): array
    {
        return json_decode($this->readFile($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function readFile(string $path): string
    {
        $bytes = is_readable($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new RuntimeException("Legacy snapshot file unavailable: {$path}");
        }
        return $bytes;
    }

    public function down(): void
    {
        throw new RuntimeException('PPB_LEGACY_SNAPSHOT_REQUIRES_REVIEWED_RECOVERY');
    }
};
