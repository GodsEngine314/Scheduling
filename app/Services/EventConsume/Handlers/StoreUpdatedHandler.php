<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class StoreUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $id = $this->asInt(data_get($event, 'data.store_id') ?? data_get($event, 'store_id'));

        // fallback if producer sends data.store.id
        if ($id <= 0) {
            $id = $this->asInt(data_get($event, 'data.store.id') ?? data_get($event, 'store.id'));
        }

        if ($id <= 0) {
            throw new \Exception('StoreUpdatedHandler: missing/invalid store id');
        }

        $changed = data_get($event, 'data.changed_fields', []);
        if (!is_array($changed)) {
            $changed = [];
        }

        // store_number is the only column this projection carries, so it is the
        // only delta worth applying. Everything else auth sends is ignored here.
        $storeNumberTo = $this->extractDeltaToScalar($changed, 'store_number')
            ?? $this->extractDeltaToScalar($changed, 'store_id')
            ?? $this->trimmedString(data_get($event, 'data.store.store_number'));

        DB::transaction(function () use ($id, $storeNumberTo) {
            $store = Store::query()->find($id);

            // Eventual consistency: the created event may not have landed yet.
            if (!$store) {
                $store = new Store();
                $store->id = $id;
            }

            $update = [];

            if ($storeNumberTo !== null) {
                $update['store_number'] = $storeNumberTo;
            }

            if (empty($update)) {
                return;
            }

            if (!$store->exists) {
                $store->fill($update);
                $store->save();
            } else {
                $store->update($update);
            }
        });
    }

    /**
     * Extract the delta "to" value safely.
     *
     * Supports:
     *  changed_fields[field] = ['from' => X, 'to' => Y]
     *  changed_fields[field] = 'value'
     */
    private function extractDeltaToScalar(array $changed, string $field): ?string
    {
        if (!array_key_exists($field, $changed)) {
            return null;
        }

        $v = $changed[$field];

        if (is_array($v) && array_key_exists('to', $v)) {
            return $this->trimmedString($v['to']);
        }

        return $this->trimmedString($v);
    }

    private function trimmedString(mixed $v): ?string
    {
        if (is_string($v)) {
            $v = trim($v);
            return $v === '' ? null : $v;
        }

        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        return null;
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
