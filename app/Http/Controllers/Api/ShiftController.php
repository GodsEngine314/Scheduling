<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ShiftDeleteRequest;
use App\Http\Requests\Api\ShiftIndexRequest;
use App\Http\Requests\Api\ShiftSplitRequest;
use App\Http\Requests\Api\ShiftStoreRequest;
use App\Http\Requests\Api\ShiftUpdateRequest;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use App\Services\Scheduling\LaborCostEstimator;
use App\Services\Scheduling\ShiftService;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * Building the schedule. Every rule lives in ShiftService; this maps HTTP onto
 * it.
 *
 * TWO THINGS THIS CONTROLLER DECIDES, because they are HTTP concerns rather
 * than domain ones:
 *
 *   The pay gate. A per-shift estimate is the employee's hourly rate multiplied
 *   by hours a caller can already see, so it is opt-in — see
 *   ApiController::wantsCost().
 *
 *   Handing conflicts back with a saved shift. Conflicts WARN, NEVER BLOCK, so
 *   a save returns them alongside the row: a warning nobody is shown is not a
 *   warning. They are in meta rather than the resource because they are a
 *   statement about this request, not a property of the shift.
 */
class ShiftController extends ApiController
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly LaborCostEstimator $costs,
        private readonly BusinessDay $businessDay,
    ) {}

    /** The week view. */
    public function index(ShiftIndexRequest $request): JsonResponse
    {
        $storeId = (int) $request->validated('store');
        $from = (string) $request->validated('from');
        $to = (string) $request->validated('to');

        // employee and position are eager-loaded because the resource names
        // them; without this the week view is one query per row.
        $shifts = Shift::query()
            ->with(['employee', 'position'])
            ->forStoreBetween($storeId, $from, $to)
            ->get();

        return $this->collection($shifts, $request)
            ->additional(['meta' => [
                'store_id' => $storeId,
                'from' => $from,
                'to' => $to,
                // Once, here, rather than on every row: the resource publishes
                // UTC and this is what turns it into a wall clock.
                'timezone' => $this->businessDay->timezoneFor($storeId),
                'count' => $shifts->count(),
            ]])
            ->response();
    }

    public function store(ShiftStoreRequest $request): JsonResponse
    {
        return $this->attempt(function () use ($request): JsonResponse {
            $shift = $this->shifts->create(array_merge($request->toAttributes(), [
                // From the resolved user, never the body.
                'created_by_user_id' => $this->actingUserId($request),
            ]));

            return $this->single($shift, $request)->response()->setStatusCode(201);
        });
    }

    public function update(ShiftUpdateRequest $request, Shift $shift): JsonResponse
    {
        return $this->attempt(fn (): JsonResponse => $this->single(
            $this->shifts->update($shift, $request->toAttributes()),
            $request,
        )->response());
    }

    /**
     * Soft delete, defaulting to 'following'. 204: the caller already knows
     * what it asked to delete, and for a series the survivors are a re-fetch of
     * the week rather than something to squeeze into a delete response.
     */
    public function destroy(ShiftDeleteRequest $request, Shift $shift): JsonResponse
    {
        return $this->attempt(function () use ($request, $shift): JsonResponse {
            $this->shifts->delete($shift, $request->seriesRule());

            // Symfony strips the body from a 204 when it prepares the response.
            return response()->json(null, 204);
        });
    }

    /**
     * 11:00-14:00 becomes that plus 17:00-21:00. Returns the NEW part, 201,
     * carrying the split_group_id that ties it to the first.
     */
    public function split(ShiftSplitRequest $request, Shift $shift): JsonResponse
    {
        return $this->attempt(function () use ($request, $shift): JsonResponse {
            [$start, $end] = $request->secondPart($this->businessDay, $shift);

            $secondPart = $this->shifts->split($shift, $start, $end);

            return $this->single($secondPart, $request)->response()->setStatusCode(201);
        });
    }

    /**
     * Everything wrong with this shift that a human should see. Always 200 —
     * these are warnings, and an empty list is a perfectly good answer.
     */
    public function conflicts(Shift $shift): JsonResponse
    {
        $conflicts = $this->shifts->conflicts($shift);

        return response()->json([
            'data' => $conflicts,
            'meta' => [
                'shift_id' => (int) $shift->id,
                'count' => count($conflicts),
                // Said out loud so a client does not build a save gate on it.
                'blocking' => false,
            ],
        ]);
    }

    /**
     * One shift, with its conflicts and — if asked for — its cost.
     *
     * The relations are (re)loaded here because a shift straight out of the
     * service carries none, and the resource names the employee and position.
     */
    private function single(Shift $shift, Request $request): ShiftResource
    {
        $resource = ShiftResource::make($shift->load(['employee', 'position']));

        if ($this->wantsCost($request)) {
            $resource->withEstimatedCost($this->costs->estimateShift($shift));
        }

        return $resource->additional(['meta' => [
            'conflicts' => $this->shifts->conflicts($shift),
        ]]);
    }

    /**
     * A list of shifts, each carrying its own estimate when cost was asked for.
     *
     * LaborCostEstimator memoises "this employee's rate on this date", so a
     * week of shifts costs one query per distinct employee-and-date pair rather
     * than one per shift. Nothing is queried at all when the gate is closed.
     *
     * @param  Collection<int, Shift>  $shifts
     */
    private function collection(Collection $shifts, Request $request): AnonymousResourceCollection
    {
        $wantsCost = $this->wantsCost($request);

        // Already-wrapped resources are passed straight through by
        // ResourceCollection rather than wrapped a second time, which is what
        // lets each row carry a value the collection itself knows nothing about.
        return ShiftResource::collection($shifts->map(
            fn (Shift $shift): ShiftResource => $wantsCost
                ? ShiftResource::make($shift)->withEstimatedCost($this->costs->estimateShift($shift))
                : ShiftResource::make($shift),
        ));
    }
}
