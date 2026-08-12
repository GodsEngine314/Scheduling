<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\WorkSegmentIndexRequest;
use App\Http\Requests\Api\WorkSegmentStoreRequest;
use App\Http\Requests\Api\WorkSegmentUpdateRequest;
use App\Http\Resources\WorkSegmentResource;
use App\Models\WorkSegment;
use App\Services\Scheduling\WorkSegmentService;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Actual worked hours: what the day close signs off.
 *
 * No pay gate anywhere in here. Hours are not pay — a manager approving a
 * timesheet has to see them — and nothing on this endpoint touches
 * employee_pay_histories.
 */
class WorkSegmentController extends ApiController
{
    public function __construct(
        private readonly WorkSegmentService $segments,
        private readonly BusinessDay $businessDay,
    ) {}

    /** One store, one business date, with the day-close views as filters. */
    public function index(WorkSegmentIndexRequest $request): JsonResponse
    {
        $storeId = (int) $request->validated('store');
        $date = (string) $request->validated('date');
        $employeeId = $request->validated('employee_id');

        // unapproved and open_punches are disjoint by definition — unapproved
        // requires a time_out and an open punch has none — so asking for both
        // correctly returns nothing. The day-close screen asks for them
        // separately, which is the whole point of keeping them apart.
        $segments = WorkSegment::query()
            ->with(['employee', 'position'])
            ->forBoard($storeId, $date)
            ->when($request->boolean('unapproved'), fn (Builder $query) => $query->unapproved())
            ->when($request->boolean('open_punches'), fn (Builder $query) => $query->openPunches())
            ->when($request->boolean('unmatched'), fn (Builder $query) => $query->unmatched())
            ->when($employeeId !== null, fn (Builder $query) => $query->where('employee_id', $employeeId))
            ->get();

        return WorkSegmentResource::collection($segments)
            ->additional(['meta' => [
                'store_id' => $storeId,
                'business_date' => $date,
                'timezone' => $this->businessDay->timezoneFor($storeId),
                'count' => $segments->count(),
                'open_punches' => $segments->filter->isOpenPunch()->count(),
            ]])
            ->response();
    }

    /** The "forgot to clock in" workflow. */
    public function store(WorkSegmentStoreRequest $request): JsonResponse
    {
        return $this->attempt(function () use ($request): JsonResponse {
            $segment = $this->segments->create($request->toAttributes());

            return WorkSegmentResource::make($segment->load(['employee', 'position']))
                ->response()
                ->setStatusCode(201);
        });
    }

    /**
     * The Change Shift workflow: correcting the times on a punch.
     *
     * The correction clears manager_approval unless reapprove was explicitly
     * sent — a segment must not stay signed off for hours nobody has since
     * looked at.
     */
    /**
     * Approve one segment's hours.
     *
     * Per segment, never in bulk: each employee's hours are signed off
     * individually so nobody clears a day's list without looking at it. An open
     * punch is refused by the service — there are no hours to approve yet.
     */
    public function approve(Request $request, WorkSegment $workSegment): JsonResponse
    {
        return $this->attempt(function () use ($request, $workSegment): JsonResponse {
            $approved = $this->segments->approve($workSegment, $this->actingUserId($request));

            return WorkSegmentResource::make($approved->load(['employee', 'position']))->response();
        });
    }

    public function update(WorkSegmentUpdateRequest $request, WorkSegment $workSegment): JsonResponse
    {
        return $this->attempt(function () use ($request, $workSegment): JsonResponse {
            [$timeIn, $timeOut] = $request->correctedTimes($this->businessDay, $workSegment);

            $segment = $this->segments->correctTimes(
                $workSegment,
                $timeIn,
                $timeOut,
                $request->boolean('reapprove'),
                $this->actingUserId($request),
            );

            return WorkSegmentResource::make($segment->load(['employee', 'position']))->response();
        });
    }

    /** Soft delete. The hours stay recoverable; a punch is evidence. */
    public function destroy(WorkSegment $workSegment): JsonResponse
    {
        return $this->attempt(function () use ($workSegment): JsonResponse {
            $this->segments->delete($workSegment);

            return response()->json(null, 204);
        });
    }

}
