<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\BoardIndexRequest;
use App\Services\Scheduling\BoardService;
use Illuminate\Http\JsonResponse;

/**
 * The Figure 12/13 board: one store, one day, plan against reality.
 *
 * BoardService already assembles the whole page in two indexed queries, so this
 * controller does exactly two things — call it, and apply the pay gate.
 */
class BoardController extends ApiController
{
    public function __construct(private readonly BoardService $board) {}

    public function index(BoardIndexRequest $request): JsonResponse
    {
        $board = $this->board->forDate(
            (int) $request->validated('store'),
            (string) $request->validated('date'),
        );

        // The day's TOTALS stay — a shift manager needs to know what the day
        // costs. The per-employee lines are a colleague's hourly rate with the
        // hours still attached, so they go unless ?include=cost asked for them.
        //
        // Removed rather than emptied: an empty list reads as "nobody is
        // scheduled", which would be a lie about a full board.
        if (! $this->wantsCost($request)) {
            unset($board['cost']['per_employee']);
        }

        return response()->json(['data' => $board]);
    }
}
