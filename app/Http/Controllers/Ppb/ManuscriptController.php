<?php
namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Models\PpbAssignment;
use App\Models\PpbGame;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * PPB W2 | 2026-11-06 | Manuscript creation, revision and editorial approval.
 * No WordPress writes. Imported historical submissions stay immutable.
 */
class ManuscriptController extends Controller
{
    /** Backend authority only. An account link is NOT a verified LINE identity. */
    private function identity(): int
    {
        $account = auth('ppb')->user();
        abort_unless($account && $account->is_active && $account->hasPpbPermission('schedules.manage'), 403,
            'BACKEND_MANUSCRIPT_PERMISSION_REQUIRED; ANALYST_IDENTITY_DELEGATION_UNAVAILABLE');
        return (int) $account->getAuthIdentifier();
    }

    private function canReview(PpbGame $game): bool
    {
        return (int)$game->reviewer_analyst_id > 0;
    }

    private function matchesAssignment(object $row, PpbAssignment $assignment): bool
    {
        return in_array($assignment->kind, ['pick','bonus','long'], true)
            && (int)$assignment->current_analyst_id > 0
            && (int) $row->game_id === (int) $assignment->game_id
            && (int) $row->assignment_id === (int) $assignment->id
            && $row->kind === $assignment->kind
            && $row->submitted_analyst_id !== null
            && (int) $row->submitted_analyst_id === (int) $assignment->current_analyst_id;
    }

    private function assertEditableGame(PpbGame $game): void
    {
        if ($game->status === 'cancelled') {
            throw ValidationException::withMessages(['manuscript' => '已取消賽事的稿件僅供查看。']);
        }
        // Historical WP success and unresolved WP records are never edited by this
        // local-only workflow; a separate WP-aware editorial update must be designed.
        if ($game->hasVerifiedWp() || $game->hasUnresolvedWp()) {
            throw ValidationException::withMessages(['manuscript' => '此場已有 WP 發布或待查紀錄，禁止以新版草稿覆蓋歷史內容。']);
        }
    }

    private function latestForAssignment(int $assignmentId): ?object
    {
        $rows = DB::table('ppb_submissions')->where('assignment_id', $assignmentId)
            ->orderBy('id')->limit(2)->lockForUpdate()->get();
        if ($rows->count() > 1) {
            throw ValidationException::withMessages(['manuscript' => '同一指派有多筆投稿，須先人工對帳，不可自動選擇或覆蓋。']);
        }
        return $rows->first();
    }

    private function revision(?object $row, PpbAssignment $assignment): string
    {
        // Bind empty drafts too: a stale form must not become another analyst's
        // submission after a substitution, nor review a later resubmitted body.
        $fields = ['id','game_id','assignment_id','kind','submitted_analyst_id','status',
            'recommend_pick','analysis_text','content_body','submitted_at','updated_at',
            'legacy_source_pick_id','reviewed_by_account_id','reviewed_at','review_note'];
        return hash('sha256', json_encode([
            'format'=>'ppb-manuscript-revision-v2',
            'assignment'=>[(int)$assignment->id,(int)$assignment->game_id,$assignment->kind,
                $assignment->slot_no,(int)$assignment->original_analyst_id,
                (int)$assignment->current_analyst_id,$assignment->status,(string)$assignment->updated_at],
            'submission'=>$row ? array_map(fn($field)=>$row->$field ?? null,$fields) : null,
        ], JSON_THROW_ON_ERROR));
    }

    private function event(int $gameId, int $accountId, string $kind, array $details): void
    {
        DB::table('ppb_schedule_events')->insert([
            'game_id' => $gameId,
            'actor_id' => $accountId,
            'event' => $kind,
            // Never copy submission body, recommendation or private reviewer notes to logs.
            'detail_json' => json_encode(['actor_boundary'=>'backend_management'] + $details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    public function index(): View
    {
        $this->identity();
        $admin = true;
        $games = PpbGame::query()->withCount('submissions');
        $games = $games->orderByDesc('recommended_at')->orderByDesc('id')->paginate(20);
        return view('ppb.manuscripts-index', compact('games', 'admin'));
    }

    public function show(PpbGame $game): View
    {
        $this->identity();
        $admin = true;
        $assignments = $game->assignments()->with('currentAnalyst:id,name,display_name')
            ->where('status', 'active')->get()->sortBy(function ($a) {
                $rank = ['pick' => 0, 'bonus' => 1, 'long' => 2];
                return sprintf('%02d-%03d', $rank[$a->kind] ?? 9, $a->slot_no ?? 0);
            })->values();
        $rows = DB::table('ppb_submissions')->where('game_id', $game->id)
            ->orderBy('id')->get()->groupBy('assignment_id');
        $editable = $game->status !== 'cancelled' && !$game->hasVerifiedWp() && !$game->hasUnresolvedWp();
        $reviewer = $this->canReview($game);
        $canEdit = [];
        $tokens = [];
        $validRows = [];
        foreach ($assignments as $assignment) {
            $records = $rows->get($assignment->id, collect());
            // Preserve any historical duplicates as read-only and mark them for attention.
            $row = $records->count() === 1 ? $records->first() : null;
            $tokens[$assignment->id] = $records->count() <= 1 ? $this->revision($row, $assignment) : 'conflict';
            $validRows[$assignment->id] = $row && $row->legacy_source_pick_id === null
                && $row->status !== 'legacy_imported' && $this->matchesAssignment($row, $assignment);
            $canEdit[$assignment->id] = $editable
                && (!$row || $validRows[$assignment->id])
                && $records->count() <= 1
                && (!$row || in_array($row->status, ['draft', 'changes_requested'], true));
        }
        return view('ppb.manuscripts-game', compact(
            'game', 'assignments', 'rows', 'admin', 'reviewer', 'canEdit', 'tokens', 'editable', 'validRows'
        ));
    }

    public function save(Request $request, PpbAssignment $assignment): RedirectResponse
    {
        $accountId = $this->identity();
        $data = $request->validate([
            'mode' => ['required', Rule::in(['save', 'submit'])],
            'revision_token' => ['required', 'string', 'max:64'],
            'recommend_pick' => ['nullable', 'string', 'max:3000'],
            'analysis_text' => ['nullable', 'string', 'max:60000'],
        ]);
        $kind = $assignment->kind;
        abort_unless(in_array($kind, ['pick', 'bonus', 'long'], true), 422);
        $recommend = trim((string) ($data['recommend_pick'] ?? ''));
        $analysis = trim((string) ($data['analysis_text'] ?? ''));
        if ($data['mode'] === 'submit') {
            if ($analysis === '' || ($kind !== 'long' && $recommend === '')) {
                throw ValidationException::withMessages(['analysis_text' => '正式送稿須填寫分析內容；短文與 BONUS 另須填寫推薦選擇。']);
            }
        }
        DB::transaction(function () use ($assignment, $accountId, $data, $kind, $recommend, $analysis) {
            $game = PpbGame::whereKey($assignment->game_id)->lockForUpdate()->firstOrFail();
            $current = PpbAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'active' || $current->game_id !== $game->id || $current->kind !== $kind ||
                $current->current_analyst_id !== $assignment->current_analyst_id) {
                throw ValidationException::withMessages(['manuscript' => '指派資料已異動，請重新整理後操作。']);
            }
            $this->assertEditableGame($game);
            $row = $this->latestForAssignment($current->id);
            if ($data['revision_token'] !== $this->revision($row, $current)) {
                throw ValidationException::withMessages(['manuscript' => '稿件已被其他操作更新，請重新整理，避免覆蓋較新的內容。']);
            }
            if ($row && ($row->status === 'legacy_imported' || $row->legacy_source_pick_id !== null)) {
                throw ValidationException::withMessages(['manuscript' => '歷史匯入稿件只能閱讀，不能用新版草稿覆蓋。']);
            }
            if ($row && !$this->matchesAssignment($row, $current)) {
                throw ValidationException::withMessages(['manuscript'=>'稿件來源與指派不一致，須先對帳。']);
            }
            if ($row && !in_array($row->status, ['draft', 'changes_requested'], true)) {
                throw ValidationException::withMessages(['manuscript' => '這筆稿件已送審或屬於歷史資料，不能直接覆蓋。']);
            }
            $status = $data['mode'] === 'submit' ? 'submitted' : 'draft';
            $payload = [
                'recommend_pick' => $recommend !== '' ? $recommend : null,
                'analysis_text' => $analysis !== '' ? $analysis : null,
                'status' => $status,
                'submitted_at' => $status === 'submitted' ? now() : null,
                'last_edited_by_account_id' => $accountId,
                // Retain prior reviewer feedback when a returned manuscript is revised.
                // Only a fresh review decision may change reviewed_* and review_note.
                'updated_at' => now(),
            ];
            if ($row) {
                DB::table('ppb_submissions')->where('id', $row->id)->update($payload);
                $id = (int) $row->id;
            } else {
                $id = (int) DB::table('ppb_submissions')->insertGetId(array_merge($payload, [
                    'game_id' => $game->id,
                    'assignment_id' => $current->id,
                    'kind' => $current->kind,
                    'submitted_analyst_id' => $current->current_analyst_id,
                    'content_body' => null,
                    'legacy_source_pick_id' => null,
                    'reviewed_by_account_id' => null,
                    'reviewed_at' => null,
                    'review_note' => null,
                    'created_at' => now(),
                ]));
            }
            $this->event($game->id, $accountId,
                $status === 'submitted' ? 'manuscript.submitted' : 'manuscript.draft_saved',
                ['submission_id' => $id, 'assignment_id' => $current->id, 'kind' => $kind]
            );
        }, 3);
        return redirect()->route('ppb.manuscripts.game', $assignment->game_id)
            ->with('status', $data['mode'] === 'submit' ? '稿件已正式送審。' : '草稿已儲存。');
    }

    public function review(Request $request, int $submission): RedirectResponse
    {
        $accountId = $this->identity();
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'changes_requested'])],
            'revision_token' => ['required', 'string', 'size:64'],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($data['decision'] === 'changes_requested' && trim((string) ($data['review_note'] ?? '')) === '') {
            throw ValidationException::withMessages(['review_note' => '退回修改須填寫原因。']);
        }
        $note = trim((string) ($data['review_note'] ?? ''));
        $gameId = DB::transaction(function () use ($submission, $accountId, $data, $note) {
            $first = DB::table('ppb_submissions')->where('id', $submission)->first();
            abort_unless($first, 404);
            $game = PpbGame::whereKey($first->game_id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canReview($game), 403);
            $this->assertEditableGame($game);
            $assignment = $first->assignment_id
                ? PpbAssignment::whereKey($first->assignment_id)->lockForUpdate()->first() : null;
            $row = DB::table('ppb_submissions')->where('id', $submission)->lockForUpdate()->first();
            if (!$row || $row->status !== 'submitted' || !$row->assignment_id || $row->legacy_source_pick_id !== null) {
                throw ValidationException::withMessages(['manuscript' => '只有新版已送審的稿件可核稿；歷史資料不得直接變更。']);
            }
            if (!$assignment || (int)$row->game_id !== (int)$game->id
                || $assignment->status !== 'active' || !$this->matchesAssignment($row, $assignment)) {
                throw ValidationException::withMessages(['manuscript' => '稿件指派資訊不一致，禁止核稿。']);
            }
            $this->latestForAssignment($assignment->id); // Duplicates require reconciliation, not choosing one.
            if (!hash_equals($this->revision($row, $assignment), $data['revision_token'])) {
                throw ValidationException::withMessages(['manuscript'=>'稿件或指派已更新，請重新讀取後核稿。']);
            }
            DB::table('ppb_submissions')->where('id', $row->id)->update([
                'status' => $data['decision'],
                'review_note' => $note !== '' ? $note : null,
                'reviewed_by_account_id' => $accountId,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->event($game->id, $accountId,
                $data['decision'] === 'approved' ? 'manuscript.review_approved' : 'manuscript.review_changes',
                ['submission_id' => $row->id, 'assignment_id' => $row->assignment_id, 'decision' => $data['decision']]
            );
            return (int) $game->id;
        }, 3);
        return redirect()->route('ppb.manuscripts.game', $gameId)
            ->with('status', $data['decision'] === 'approved' ? '稿件已核准。' : '已退回修改。');
    }
}
