<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class StoreCreatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $storePayload = $this->extractStorePayload($event);

        $id = $this->asInt(data_get($storePayload, 'id'));
        if ($id <= 0) {
            throw new \Exception('StoreCreatedHandler: missing/invalid store.id');
        }

        // IMPORTANT: consumer stores.store must be store_id (manual string), not name.
        $storeIdString = $this->extractStoreIdString($storePayload);

        DB::transaction(function () use ($id, $storeIdString) {
            Store::query()->updateOrCreate(
                ['id' => $id],
                [
                    'store_number' => $storeIdString,
                ]
            );
        });
    }

    private function extractStorePayload(array $event): array
    {
        $store = data_get($event, 'data.store');
        if (is_array($store))
            return $store;

        $store = data_get($event, 'store');
        if (is_array($store))
            return $store;

        $store = data_get($event, 'payload.store');
        if (is_array($store))
            return $store;

        throw new \Exception('StoreCreatedHandler: store payload not found in event');
    }

    private function extractStoreIdString(array $storePayload): string
    {
        // Prefer store_id (manual string), fallback to anything usable.
        $v = data_get($storePayload, 'store_id');

        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        // Some producers might call it "store_number" or "store" already:
        foreach (['store_number', 'store'] as $key) {
            $v = data_get($storePayload, $key);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        // Last resort: if only numeric id exists, store it as string so the record is not broken.
        $id = data_get($storePayload, 'id');
        if (is_scalar($id) && (string) $id !== '') {
            return (string) $id;
        }

        return 'UNKNOWN';
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v))
            return $v;
        if (is_string($v) && ctype_digit($v))
            return (int) $v;
        if (is_numeric($v))
            return (int) $v;
        return 0;
    }
}
