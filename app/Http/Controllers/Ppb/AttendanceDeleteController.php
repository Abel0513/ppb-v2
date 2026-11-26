<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Services\Ppb\Evaluation\AttendanceDeletions;
use App\Services\Ppb\Evaluation\EvaluationInputs;
use App\Services\Ppb\Evaluation\EvaluationWriteLock;
use App\Services\Ppb\Evaluation\LegacyAttendanceSource;
use App\Services\Ppb\Evaluation\LocalEvaluationData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AttendanceDeleteController extends Controller
{
    private const NATIVE_RECEIPT = 'attendance_deleted';

    public function __invoke(Request $request, LegacyAttendanceSource $source, LocalEvaluationData $data, EvaluationInputs $inputs): JsonResponse
    {
        $base = $request->validate(['source' => 'required|string|in:legacy,v2', 'request_id' => 'required|string|uuid']);
        $legacy = $base['source'] === 'legacy';
        $positiveId = ['required', 'integer', 'min:1', static function ($attribute, $value, $fail): void {
            if (!is_int($value) && !is_string($value)) {
                $fail('The :attribute field must be an integer.');
            }
        }];
        $target = $request->validate($legacy ? [
            'legacy_record_id' => $positiveId,
            'subject_type' => 'missing', 'subject_id' => 'missing', 'game_id' => 'missing',
        ] : [
            'subject_type' => 'required|string|in:legacy,v2',
            'subject_id' => $positiveId, 'game_id' => $positiveId,
            'legacy_record_id' => 'missing',
        ]);
        $requestId = strtolower($base['request_id']);
        $actor = (int) $request->user('ppb')->getAuthIdentifier();

        $delete = function (callable $assertHeld) use ($request, $legacy, $target, $requestId, $actor, $source, $data, $inputs): array {
            $assertHeld();
            // The evaluation lock serializes receipt creation. Read the JSON
            // lookup without locking unrelated schedule event rows or gaps.
            $nativeReceipt = DB::table('ppb_schedule_events')->where('event', self::NATIVE_RECEIPT)
                ->where('detail_json->request_id', $requestId)->first();

            return DB::transaction(function () use ($assertHeld, $request, $legacy, $target, $requestId, $actor, $source, $data, $inputs, $nativeReceipt): array {
                $assertHeld();
                $tableReady = Schema::hasTable(AttendanceDeletions::TABLE);
                abort_if($legacy && !$tableReady, 503, '出缺勤刪除功能尚未就緒。');

                $legacyReceipt = $tableReady ? DB::table(AttendanceDeletions::TABLE)
                    ->where('request_id', $requestId)->lockForUpdate()->first() : null;
                if ($legacyReceipt) {
                    abort_unless($legacy && (int) $legacyReceipt->legacy_record_id === (int) $target['legacy_record_id']
                        && (int) $legacyReceipt->actor_id === $actor, 409, '此請求編號已使用。');

                    return ['request_id' => $requestId];
                }

                if ($nativeReceipt) {
                    $receipt = json_decode($nativeReceipt->detail_json, true, flags: JSON_THROW_ON_ERROR);
                    abort_unless(!$legacy && (int) $nativeReceipt->game_id === (int) $target['game_id']
                        && (int) $nativeReceipt->actor_id === $actor && $receipt['subject_type'] === $target['subject_type']
                        && $receipt['subject_id'] === (int) $target['subject_id'], 409, '此請求編號已使用。');

                    return ['request_id' => $requestId];
                }

                $event = $legacy
                    ? $this->deleteLegacy((int) $target['legacy_record_id'], $requestId, $actor, $source)
                    : $this->deleteNative($target, $requestId, $actor, $data, $inputs);

                DB::table('ppb_auth_audits')->insert([
                    'account_id' => $actor, 'event' => $event,
                    'ip' => substr((string) $request->ip(), 0, 45),
                    'user_agent' => substr((string) $request->userAgent(), 0, 255),
                    'created_at' => now(),
                ]);

                return ['request_id' => $requestId];
            });
        };

        // Share production exclusion with evaluation saves, including their
        // source-hash check. SQLite serializes writes for the isolated tests.
        $result = DB::connection()->getDriverName() === 'sqlite'
            ? $delete(static function (): void {})
            : (new EvaluationWriteLock)->run($delete);

        return response()->json($result);
    }

    private function deleteLegacy(int $id, string $requestId, int $actor, LegacyAttendanceSource $source): string
    {
        $records = DB::table(AttendanceDeletions::TABLE);
        $existing = (clone $records)->where('legacy_record_id', $id)->lockForUpdate()->first();
        abort_if($existing && $existing->restored_at === null, 409, '此出缺勤紀錄已刪除。');
        try {
            $row = $source->find($id);
        } catch (\RuntimeException $error) {
            report($error);
            abort(503, '舊系統出缺勤暫時無法讀取。');
        }
        abort_if($row === null, 404, '找不到出缺勤紀錄。');

        $values = [
            'legacy_gid' => $row['gid'], 'subject_type' => $row['subject_type'], 'subject_id' => $row['subject_id'],
            'attendance_status' => $row['attendance_status'], 'recommended_time' => $row['recommended_time'],
            'actor_id' => $actor, 'request_id' => $requestId, 'deleted_at' => now(),
            'restored_at' => null, 'updated_at' => now(),
        ];
        if ($existing) {
            $records->where('id', $existing->id)->update($values);
        } else {
            $records->insert($values + ['legacy_record_id' => $id, 'created_at' => now()]);
        }

        return 'attendance.deleted#legacy:'.$id;
    }

    private function deleteNative(array $target, string $requestId, int $actor, LocalEvaluationData $data, EvaluationInputs $inputs): string
    {
        $gameId = (int) $target['game_id'];
        $type = $target['subject_type'];
        $id = (int) $target['subject_id'];
        $game = DB::table('ppb_games')->where('id', $gameId)->lockForUpdate()->first(['id']);
        abort_unless($game && Schema::hasTable('ppb_evaluation_attendance'), 404, '找不到出缺勤紀錄。');

        $subject = $data->roster()[$type.':'.$id] ?? null;
        abort_if($subject === null, 404, '找不到出缺勤紀錄。');
        $corrections = $data->corrections(gameId: $gameId);
        $rows = $inputs->attendanceRows($data->records(attendanceOnly: true, gameId: $gameId), $corrections);
        abort_unless(collect($rows)->contains(fn ($row) => $row['subject_type'] === $type && $row['subject_id'] === $id),
            404, '找不到出缺勤紀錄。');

        $inputs->writeAttendance($subject, $gameId, null, $actor);
        DB::table('ppb_schedule_events')->insert([
            'game_id' => $gameId, 'actor_id' => $actor, 'event' => self::NATIVE_RECEIPT,
            'detail_json' => json_encode([
                'request_id' => $requestId, 'subject_type' => $type, 'subject_id' => $id,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $event = 'attendance.deleted#v2:'.$type.':'.$id.':'.$gameId;

        // The audit column is 48 characters. The receipt retains full IDs.
        return strlen($event) <= 48 ? $event : 'attendance.deleted#v2';
    }
}
