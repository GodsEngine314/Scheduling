<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {

        $id = $this->asInt(data_get($event, 'data.user_id') ?? data_get($event, 'user_id'));

        // fallback if some producers send data.user.id
        if ($id <= 0) {
            $id = $this->asInt(data_get($event, 'data.user.id') ?? data_get($event, 'user.id'));
        }

        if ($id <= 0) {
            throw new \Exception('UserUpdatedHandler: missing/invalid user id');
        }

        $changed = data_get($event, 'data.changed_fields');
        if (!is_array($changed)) {
            $changed = [];
        }

        // Extract safe scalar "to" values
        $nameTo  = $this->extractDeltaToScalar($changed, 'name');
        $emailTo = $this->extractDeltaToScalar($changed, 'email');

        DB::transaction(function () use ($id, $nameTo, $emailTo) {
            // IMPORTANT: do not create users here (bus is source of truth)
            $exists = User::query()->whereKey($id)->exists();
            if (!$exists) {
                throw new \Exception("UserUpdatedHandler: user {$id} not synced yet");
            }

            $update = [];

            if ($nameTo !== null) {
                $update['name'] = $nameTo;
            }

            if ($emailTo !== null) {
                $update['email'] = $emailTo;
            }

            if (empty($update)) {
                return;
            }

            /**
             * Query Builder update, deliberately, to bypass Eloquent casts and
             * mutators, model observers and package hooks. Nothing downstream of
             * a projection write should be able to fire.
             */
            DB::table('users')
                ->where('id', $id)
                ->update($update);
        });
    }

    /**
     * Extract the delta "to" value safely.
     *
     * Supports:
     *  changed_fields[field] = ['from' => X, 'to' => Y]
     *  changed_fields[field] = 'value'
     *
     * Returns:
     *  - string if scalar
     *  - null otherwise
     */
    private function extractDeltaToScalar(array $changed, string $field): ?string
    {
        if (!array_key_exists($field, $changed)) {
            return null;
        }

        $v = $changed[$field];

        // Standard delta shape: {from,to}
        if (is_array($v) && array_key_exists('to', $v)) {
            $to = $v['to'];

            if (is_string($to)) {
                $to = trim($to);
                return $to === '' ? null : $to;
            }

            if (is_int($to) || is_float($to) || is_bool($to)) {
                return (string) $to;
            }

            // array/object -> ignore
            return null;
        }

        // Direct scalar
        if (is_string($v)) {
            $v = trim($v);
            return $v === '' ? null : $v;
        }

        if (is_int($v) || is_float($v) || is_bool($v)) {
            return (string) $v;
        }

        return null;
    }


    private function asInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return 0;
    }
}
