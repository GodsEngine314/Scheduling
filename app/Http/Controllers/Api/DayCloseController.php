<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\DayCloseCheckRequest;
use App\Http\Requests\Api\DayCloseStoreRequest;
use App\Services\Scheduling\DayCloseService;
use Illuminate\Http\JsonResponse;

/**
 * "They cannot close the day until they approve all the hours of the day."
 *
 * check() is the dry run and always answers 200, blockers and all — a manager
 * opening the day-close screen has not done anything wrong yet.
 *
 * store() is the attempt. A day that will not close comes back 422 carrying
 * DayCloseService's blockers verbatim, which name the employees. "3 unapproved
 * segments" is not something a manager can act on; "Dana Okafor, still clocked
 * in since 13:02" is. ApiController::attempt() does that rendering, because
 * SchedulingException::context() is already the machine-readable half.
 *
 * There is no day_closes table yet, so a successful close is a 200 report
 * rather than a 201 resource: nothing was created to point a URL at.
 */
class DayCloseController extends ApiController
{
    public function __construct(private readonly DayCloseService $dayClose) {}

    public function check(DayCloseCheckRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->dayClose->check(
                (int) $request->validated('store'),
                (string) $request->validated('date'),
            ),
        ]);
    }

    public function store(DayCloseStoreRequest $request): JsonResponse
    {
        return $this->attempt(fn (): JsonResponse => response()->json([
            'data' => $this->dayClose->close(
                (int) $request->validated('store_id'),
                (string) $request->validated('business_date'),
                $this->actingUserId($request),
            ),
        ]));
    }
}
