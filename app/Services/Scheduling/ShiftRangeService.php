<?php

namespace App\Services\Scheduling;

use App\Enums\PublishState;
use App\Models\Shift;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The two things you do to a WHOLE RANGE of shifts, rather than to one.
 *
 * WHY A RANGE AND NOT "ALL". Both operations here are scoped to a store and a
 * span of business dates — the span the board is showing — and never to the
 * table. "Delete all shifts" with no boundary is a button that empties the
 * estate, and the manager pressing it is looking at one week of one store. The
 * range is the thing on screen, so the range is the thing acted on, and the
 * count in the label says so out loud before anybody commits.
 *
 * ONE SHIFT AT A TIME, INSIDE THE LOOP. Neither of these is a mass UPDATE or a
 * mass DELETE with a where clause, and that is deliberate: a published shift is
 * held by Humanity under its own id, so it has to be withdrawn individually
 * before its row can go. A `delete()` with a where clause would empty the board
 * and leave a store's whole week live on everybody's roster.
 *
 * PARTIAL SUCCESS IS THE NORMAL FAILURE MODE, not an exception. Humanity can
 * refuse the fourth of nine withdrawals, and the answer is not to roll back the
 * three that worked — they are gone from the vendor and must be gone here too,
 * or the two systems disagree in the direction that has somebody turn up for a
 * cancelled shift. So every method here reports what happened per shift and the
 * caller says so; nothing throws for a vendor refusal.
 */
class ShiftRangeService
{
    /**
     * The publisher alone. NOT ShiftService, deliberately: its delete() follows
     * series_id past the range, which is the one thing these operations must not
     * do — see deleteRange().
     */
    public function __construct(private readonly SchedulePublisher $publisher) {}

    /**
     * Unlock every published shift in the range for editing.
     *
     * REPLACED A PER-CHIP BUTTON. Unpublishing was one 🔓 per shift, which is
     * the wrong grain for the thing it is for: the workflow is "unpublish, change
     * the week, republish", and a manager fixing Tuesday had to click fourteen
     * padlocks before they could touch anything. The rule it enforces is
     * unchanged — a published shift cannot be edited, moved or deleted until it
     * is unlocked — only the number of clicks is.
     *
     * LOCAL ONLY, and this is the half people get wrong. Unlocking does NOT tell
     * Humanity anything: the shifts stay live on everybody's roster exactly as
     * they were, and the next publish sends the changes as a PUT over the same
     * shift. Nothing is withdrawn and nobody's roster goes blank in between.
     *
     * @return array{total: int, unlocked: int, already: int}
     */
    public function unpublishRange(int $storeId, string $from, string $to): array
    {
        $shifts = $this->inRange($storeId, $from, $to);

        // isLocked() is Published and nothing else. A shift already Unlocked is
        // counted rather than touched — pressing this twice is not an error, and
        // reporting "0 unlocked" at somebody who can see fourteen padlocks would
        // read as a failure.
        $locked = $shifts->filter(fn (Shift $shift): bool => (bool) $shift->publish_state?->isLocked());

        $unlocked = 0;

        foreach ($locked as $shift) {
            // Through the publisher, which owns publish state and refuses a
            // shift that is not live. Individually rather than as one UPDATE so
            // that guard is actually asked each time.
            $this->publisher->unpublish($shift);
            $unlocked++;
        }

        return [
            'total' => $shifts->count(),
            'unlocked' => $unlocked,
            'already' => $shifts->count() - $locked->count(),
        ];
    }

    /**
     * Withdraw every shift in the range from Humanity, then soft-delete it.
     *
     * THE ORDER IS THE WHOLE POINT, and it is the same order the single delete
     * uses: Humanity first, local second, and a row whose withdrawal failed is
     * NOT deleted. The other way round loses the shift off the board while the
     * vendor keeps it, and once the row is gone there is nothing left to retry
     * with — somebody turns up for a shift cancelled a week ago. Refusing leaves
     * a shift the manager can try again, which is the recoverable half.
     *
     * humanity_shift_id, NOT publish_state, decides whether a withdrawal is
     * needed. A row that failed mid-publish keeps its id — recordFailure() only
     * writes the error — so it is 'failed' AND still held. The id is the only
     * honest test.
     *
     * NO SERIES EXPANSION. ShiftService::delete() follows series_id and would
     * reach dates outside the range: pressing "delete this week" would silently
     * remove next month's occurrences of a repeating shift. Here the range IS
     * the selection, so the rows deleted are exactly the rows counted in the
     * label.
     *
     * PUNCHES SURVIVE. The delete is soft, so work_segments.shift_id keeps
     * pointing at the row and a reconciliation somebody made by hand is not
     * destroyed — shift_id only drops to NULL on a hard delete. Reported, so
     * nobody has to wonder what happened to the hours.
     *
     * @return array{total: int, deleted: int, withdrawn: int, punches: int, failures: array<int,array{shift_id: int, reason: string}>}
     */
    public function deleteRange(int $storeId, string $from, string $to): array
    {
        $shifts = $this->inRange($storeId, $from, $to);

        $withdrawn = 0;
        $deletable = [];
        $failures = [];

        foreach ($shifts as $shift) {
            if ($shift->humanity_shift_id !== null) {
                try {
                    // No rule passed: we never send `repeat` on a create, so
                    // each Humanity shift stands alone and the vendor's own
                    // series rule has nothing to act on.
                    $this->publisher->withdraw($shift);
                    $withdrawn++;
                } catch (Throwable $e) {
                    // Reported, not rethrown. One vendor refusal must not
                    // abandon the rest of the week — and must not delete this
                    // row either, or Humanity keeps a shift nothing can withdraw.
                    $failures[] = [
                        'shift_id' => (int) $shift->id,
                        'reason' => class_basename($e).': '.$e->getMessage(),
                    ];

                    continue;
                }

                // withdraw() leaves the row Unpublished, so the lock below is
                // already open.
                $deletable[] = (int) $shift->id;

                continue;
            }

            /*
             * PUBLISHED, BUT HUMANITY IS NOT HOLDING IT. State drift — a publish
             * that recorded the state and lost the id. The row is locked, so
             * ShiftService would refuse to delete it, and there is nothing to
             * withdraw. Unlocking is safe precisely because the vendor has no
             * copy, and it is what lets the delete proceed instead of stranding
             * the shift as undeletable.
             */
            if ($shift->publish_state === PublishState::Published) {
                $this->publisher->unpublish($shift);
            }

            $deletable[] = (int) $shift->id;
        }

        if ($deletable === []) {
            return [
                'total' => $shifts->count(),
                'deleted' => 0,
                'withdrawn' => $withdrawn,
                'punches' => 0,
                'failures' => $failures,
            ];
        }

        // Counted BEFORE the delete: afterwards the rows are trashed and the
        // relation would read differently.
        $punches = (int) Shift::query()
            ->whereIn('id', $deletable)
            ->withCount('workSegments')
            ->get()
            ->sum('work_segments_count');

        $deleted = (int) DB::transaction(
            fn (): int => Shift::query()->whereIn('id', $deletable)->delete()
        );

        return [
            'total' => $shifts->count(),
            'deleted' => $deleted,
            'withdrawn' => $withdrawn,
            'punches' => $punches,
            'failures' => $failures,
        ];
    }

    /**
     * How many shifts each button would touch, for the labels.
     *
     * A COUNT ON THE BUTTON IS THE CONFIRMATION. "Delete all shifts" says
     * nothing about scope; "Delete all 14 shifts this week" cannot be misread,
     * and it is the only thing standing between a manager and a week they did
     * not mean to clear.
     *
     * @return array{total: int, locked: int, published_live: int}
     */
    public function summary(int $storeId, string $from, string $to): array
    {
        $shifts = $this->inRange($storeId, $from, $to);

        return [
            'total' => $shifts->count(),
            // What "unpublish all" would unlock.
            'locked' => $shifts->filter(fn (Shift $s): bool => (bool) $s->publish_state?->isLocked())->count(),
            // What "delete all" would have to withdraw from Humanity first —
            // the number worth showing, because those are the ones a delete
            // takes off somebody's roster.
            'published_live' => $shifts->filter(fn (Shift $s): bool => $s->humanity_shift_id !== null)->count(),
        ];
    }

    /**
     * The shifts on screen.
     *
     * forStoreBetween is the same scope the board renders and the publisher
     * publishes, so what these operations touch is by construction what the
     * manager is looking at.
     *
     * @return Collection<int, Shift>
     */
    private function inRange(int $storeId, string $from, string $to): Collection
    {
        return Shift::query()->forStoreBetween($storeId, $from, $to)->get();
    }
}
