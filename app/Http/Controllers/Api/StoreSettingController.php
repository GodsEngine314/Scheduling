<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreSettingUpdateRequest;
use App\Http\Resources\StoreSettingResource;
use App\Services\Scheduling\StoreSettingService;
use Illuminate\Http\JsonResponse;

/**
 * Per-store scheduling settings.
 *
 * There is no index and no store(): settings exist per store, one row at most,
 * and a store that has never been configured still answers with the defaults
 * every reader falls back to — so read is always a GET on a store id, and write
 * is always a PUT that creates the row if it is the first one.
 *
 * No destroy either. Deleting the row does not restore a "no settings" state
 * anybody wants; it silently moves the store back onto the default timezone,
 * which is the single most consequential value here.
 */
class StoreSettingController extends ApiController
{
    public function __construct(private readonly StoreSettingService $settings) {}

    public function show(int $store): JsonResponse
    {
        return StoreSettingResource::make($this->settings->forStore($store))->response();
    }

    /**
     * A timezone change re-reads every future instant at a different offset —
     * see StoreSettingService. The service refuses a zone this server does not
     * recognise rather than letting it reach CarbonImmutable::parse().
     *
     * 201 on the PUT that creates the store's first row, 200 on every one after
     * — the resource response reads wasRecentlyCreated. Left as it is rather
     * than pinned to 200: it is the honest answer to "did this make something",
     * which is exactly what a caller configuring a new store wants to know.
     */
    public function update(StoreSettingUpdateRequest $request, int $store): JsonResponse
    {
        return $this->attempt(function () use ($request, $store): JsonResponse {
            $setting = $this->settings->update($store, $request->validated());

            return StoreSettingResource::make($setting)->response();
        });
    }
}
