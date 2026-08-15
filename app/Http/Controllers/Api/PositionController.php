<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PositionResource;
use App\Models\Position;
use Illuminate\Http\JsonResponse;

/**
 * The roles a shift can be rostered as.
 *
 * READ ONLY, and that is the design rather than an unfinished endpoint.
 * `positions` is a PROJECTION of the positions carried inside
 * hiring.v1.employee.* payloads, so a row written here would be erased by the
 * next replay. New roles arrive from hiring; TCP job codes are folded in by
 * PositionSeeder against tcp_job_code_roles.
 *
 * It exists because position_id is a required-ish field on every shift and
 * work-segment write, and an API client had no way to discover a valid one —
 * the web console reads the model directly, which no external caller can do.
 *
 * Not store-scoped: a role is a company-wide vocabulary. Which roles a
 * particular store actually uses is answerable from its shifts, not from here.
 */
class PositionController extends ApiController
{
    public function index(): JsonResponse
    {
        $positions = Position::query()->orderBy('id')->get();

        return PositionResource::collection($positions)
            ->additional(['meta' => ['count' => $positions->count()]])
            ->response();
    }
}
