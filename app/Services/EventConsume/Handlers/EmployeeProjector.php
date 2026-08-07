<?php

namespace App\Services\EventConsume\Handlers;

use App\Enums\AvailabilityShiftType;
use App\Enums\DayOfWeek;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\Gender;
use App\Jobs\PushEmployeeToTcp;
use App\Models\Employee;
use App\Models\EmployeeAvailabilityWindow;
use App\Models\EmployeePayHistory;
use App\Models\EmployeePosition;
use App\Models\EmployeeStoreAssignment;
use App\Models\Position;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The whole hiring.v1.employee.created|updated projection, in one place.
 *
 * Both subjects carry the SAME thing — the full employee graph as
 * $employee->toArray() over an eager-loaded model — so both run this and both
 * are idempotent: replaying either, in any order, any number of times, lands
 * the same rows.
 *
 * DELIBERATELY NOT PROJECTED, though every one of them arrives in the payload:
 *   race, religion, t_shirt, image_path, street addresses, emergency contacts, ssn.
 * They are protected-class or purely-HR attributes with no rostering use, and
 * this is the system that decides who works when. Holding them here is
 * discrimination exposure for no benefit. Read them, drop them, never write them.
 */
class EmployeeProjector
{
    public function project(array $event): void
    {
        $payload = $this->extractEmployeePayload($event);

        $id = $this->asInt(data_get($payload, 'id'));
        if ($id <= 0) {
            throw new \Exception('EmployeeProjector: missing/invalid employee.id');
        }

        $incomingUpdatedAt = $this->asDateTime(data_get($payload, 'updated_at'));

        DB::transaction(function () use ($id, $payload, $incomingUpdatedAt) {
            $existing = Employee::query()->whereKey($id)->first();

            /**
             * STALE-EVENT GUARD. A redelivery of an older event must not clobber
             * a newer projection — JetStream guarantees delivery, not order.
             * Equal timestamps still re-project, which keeps replay idempotent.
             */
            if (
                $existing
                && $incomingUpdatedAt
                && $existing->hiring_updated_at
                && $incomingUpdatedAt->lt($existing->hiring_updated_at)
            ) {
                Log::info('EmployeeProjector: skipping stale employee event', [
                    'employee_id' => $id,
                    'incoming_updated_at' => $incomingUpdatedAt->toDateTimeString(),
                    'stored_updated_at' => $existing->hiring_updated_at->toDateTimeString(),
                ]);

                return;
            }

            // Referenced rows first: employees, employee_store_assignments and
            // employee_positions all carry FKs into these two tables, and the
            // auth store event may not have arrived yet.
            $this->upsertStores($payload);
            $this->upsertPositions($payload);

            $this->upsertEmployee($id, $payload, $existing, $incomingUpdatedAt);

            // Replace-in-transaction, not upsert: deleting first is the only way a
            // row REMOVED upstream disappears here.
            $this->replaceStoreAssignments($id, $payload);
            $this->replacePositions($id, $payload);
            $this->replacePayHistories($id, $payload);
            $this->replaceAvailabilityWindows($id, $payload);

            /**
             * Mirror the person into TCP.
             *
             * The source document has hiring doing this; hiring makes no HTTP
             * calls to TCP at all, and scheduling owns every other TCP call, so
             * the push is triggered from the event hiring already publishes.
             *
             * QUEUED, and afterCommit. Calling the vendor inline would hold the
             * JetStream consumer open on somebody else's latency: a timeout
             * would nack the event, redeliver it, and after five attempts park
             * it — losing the projection over a problem with a different
             * system. afterCommit so the worker cannot read a half-written
             * employee, or none at all on a create.
             *
             * Create, update and termination all route here. Termination is an
             * update carrying a terminated status, never a DELETE — see
             * TcpEmployeeWriter.
             */
            PushEmployeeToTcp::dispatch($id)->afterCommit();
        });
    }

    // ---------------------------------------------------------------- payload

    /**
     * The shape is not guaranteed, so hedge the same way StoreCreatedHandler does.
     */
    private function extractEmployeePayload(array $event): array
    {
        foreach (['data.employee', 'employee', 'payload.employee'] as $path) {
            $employee = data_get($event, $path);
            if (is_array($employee)) {
                return $employee;
            }
        }

        // Flat shape: the employee IS data.
        $data = data_get($event, 'data');
        if (is_array($data) && isset($data['id'], $data['first_name'])) {
            return $data;
        }

        throw new \Exception('EmployeeProjector: employee payload not found in event');
    }

    /**
     * Relation keys arrive camelCase or snake_case depending on how the producer
     * serialised the model. Accept either.
     *
     * @return array<int, mixed>
     */
    private function relation(array $payload, string $snake, string $camel): array
    {
        foreach ([$snake, $camel] as $key) {
            $value = data_get($payload, $key);
            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    // --------------------------------------------------------------- employees

    private function upsertEmployee(int $id, array $payload, ?Employee $existing, ?Carbon $incomingUpdatedAt): void
    {
        $statusHistories = $this->relation($payload, 'status_histories', 'statusHistories');
        $contacts = $this->relation($payload, 'contacts', 'contacts');
        $stores = $this->relation($payload, 'stores', 'stores');
        $positions = $this->relation($payload, 'positions', 'positions');
        $ids = $this->relation($payload, 'ids', 'ids');

        $latestStatus = $this->latestByEffectiveDate($statusHistories);
        $latestStore = $this->latestByEffectiveDate($stores);
        $latestPosition = $this->latestByEffectiveDate($positions);

        $employmentType = $this->enumValue(data_get($payload, 'employment_type'), EmploymentType::class)
            ?? $existing?->employment_type?->value;

        if ($employmentType === null) {
            throw new \Exception("EmployeeProjector: employee {$id} has no usable employment_type");
        }

        $primaryStoreId = $this->asInt(data_get($latestStore, 'store_id'));
        $primaryPositionId = $this->asInt(data_get($latestPosition, 'position_id'));

        [$tcpEmployeeId, $tcpEmployeeRecordId] = $this->extractTcpIds($ids);

        Employee::query()->updateOrCreate(
            ['id' => $id],
            [
                'first_name' => (string) data_get($payload, 'first_name', ''),
                'middle_name' => $this->trimmedString(data_get($payload, 'middle_name')),
                'last_name' => (string) data_get($payload, 'last_name', ''),

                // birth_date lives on the obsession record upstream, not on employees.
                'birth_date' => $this->asDate(data_get($this->obsession($payload), 'birth_date')),

                'gender' => $this->enumValue(data_get($payload, 'gender'), Gender::class),
                'employment_type' => $employmentType,

                'primary_store_id' => $primaryStoreId > 0 && $this->storeExists($primaryStoreId) ? $primaryStoreId : null,
                'primary_position_id' => $primaryPositionId > 0 && $this->positionExists($primaryPositionId) ? $primaryPositionId : null,

                // Column is varchar(40); a longer value would abort the whole event.
                'primary_phone' => $this->clip($this->primaryContact($contacts, 'phone'), 40),
                'primary_email' => $this->clip($this->primaryContact($contacts, 'email'), 255),

                'current_status' => $this->enumValue(data_get($latestStatus, 'status'), EmployeeStatus::class),
                'current_status_effective_date' => $this->asDate(data_get($latestStatus, 'effective_date')),

                'tcp_employee_id' => $tcpEmployeeId,
                'tcp_employee_record_id' => $tcpEmployeeRecordId,

                'hiring_updated_at' => $incomingUpdatedAt,
            ]
        );
    }

    /** The obsession relation is a HasOne, so it arrives as an object or null. */
    private function obsession(array $payload): array
    {
        $obsession = data_get($payload, 'obsession');

        return is_array($obsession) ? $obsession : [];
    }

    /**
     * The primary contact of a type, else the first usable one of that type.
     */
    private function primaryContact(array $contacts, string $type): ?string
    {
        $fallback = null;

        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }

            if ((string) data_get($contact, 'contact_type') !== $type) {
                continue;
            }

            $value = $this->trimmedString(data_get($contact, 'contact_value'));
            if ($value === null) {
                continue;
            }

            if ($this->asBool(data_get($contact, 'is_primary'))) {
                return $value;
            }

            $fallback ??= $value;
        }

        return $fallback;
    }

    /**
     * Both TCP identifiers come from the same ids[] list and are told apart only
     * by their id_type label: the one whose label also says "Record" is the
     * record id. Labels may be missing entirely, in which case we take neither.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function extractTcpIds(array $ids): array
    {
        $employeeId = null;
        $recordId = null;

        foreach ($ids as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = data_get($row, 'id_type.label') ?? data_get($row, 'idType.label');
            if (!is_string($label)) {
                continue;
            }

            $label = mb_strtolower($label);
            if (!str_contains($label, 'tcp')) {
                continue;
            }

            $value = $this->trimmedString(data_get($row, 'id_value'));
            if ($value === null) {
                continue;
            }

            if (str_contains($label, 'record')) {
                $recordId ??= $this->clip($value, 64);
            } else {
                $employeeId ??= $this->clip($value, 64);
            }
        }

        return [$employeeId, $recordId];
    }

    // ------------------------------------------------------- referenced tables

    /**
     * Upsert the stores nested inside the employee event so a shift can reference
     * one before auth's own store event has landed. A payload that carries only
     * an id gets a placeholder store_number, but never overwrites a real one.
     */
    private function upsertStores(array $payload): void
    {
        foreach ($this->relation($payload, 'stores', 'stores') as $row) {
            if (!is_array($row)) {
                continue;
            }

            $store = data_get($row, 'store');
            if (!is_array($store)) {
                continue;
            }

            $storeId = $this->asInt(data_get($store, 'id'));
            if ($storeId <= 0) {
                continue;
            }

            $storeNumber = $this->trimmedString(data_get($store, 'store_number'));

            if ($storeNumber !== null) {
                Store::query()->updateOrCreate(['id' => $storeId], ['store_number' => $storeNumber]);
            } else {
                Store::query()->firstOrCreate(['id' => $storeId], ['store_number' => (string) $storeId]);
            }
        }
    }

    private function upsertPositions(array $payload): void
    {
        foreach ($this->relation($payload, 'positions', 'positions') as $row) {
            if (!is_array($row)) {
                continue;
            }

            $position = data_get($row, 'position');
            if (!is_array($position)) {
                continue;
            }

            $positionId = $this->asInt(data_get($position, 'id'));
            if ($positionId <= 0) {
                continue;
            }

            $label = $this->trimmedString(data_get($position, 'label'));

            if ($label !== null) {
                Position::query()->updateOrCreate(
                    ['id' => $positionId],
                    [
                        'label' => $label,
                        'description' => $this->trimmedString(data_get($position, 'description')),
                    ]
                );
            } else {
                Position::query()->firstOrCreate(['id' => $positionId], ['label' => "Position {$positionId}"]);
            }
        }
    }

    // ------------------------------------------------------- replaced children

    private function replaceStoreAssignments(int $employeeId, array $payload): void
    {
        $now = now();
        $rows = [];

        foreach ($this->relation($payload, 'stores', 'stores') as $row) {
            if (!is_array($row)) {
                continue;
            }

            $storeId = $this->asInt(data_get($row, 'store_id') ?: data_get($row, 'store.id'));
            $effectiveDate = $this->asDate(data_get($row, 'effective_date'));

            if ($storeId <= 0 || $effectiveDate === null) {
                continue;
            }

            // FK is cascadeOnDelete against a store we may never have been told
            // about; skipping beats parking the whole employee.
            if (!$this->storeExists($storeId)) {
                Log::warning('EmployeeProjector: skipping store assignment for unknown store', [
                    'employee_id' => $employeeId,
                    'store_id' => $storeId,
                ]);

                continue;
            }

            $rows[] = [
                'employee_id' => $employeeId,
                'store_id' => $storeId,
                'effective_date' => $effectiveDate,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        EmployeeStoreAssignment::query()->where('employee_id', $employeeId)->delete();

        if ($rows !== []) {
            EmployeeStoreAssignment::query()->insert($rows);
        }
    }

    private function replacePositions(int $employeeId, array $payload): void
    {
        $now = now();
        $rows = [];

        foreach ($this->relation($payload, 'positions', 'positions') as $row) {
            if (!is_array($row)) {
                continue;
            }

            $positionId = $this->asInt(data_get($row, 'position_id') ?: data_get($row, 'position.id'));
            $effectiveDate = $this->asDate(data_get($row, 'effective_date'));

            if ($positionId <= 0 || $effectiveDate === null) {
                continue;
            }

            if (!$this->positionExists($positionId)) {
                Log::warning('EmployeeProjector: skipping employee position for unknown position', [
                    'employee_id' => $employeeId,
                    'position_id' => $positionId,
                ]);

                continue;
            }

            $rows[] = [
                'employee_id' => $employeeId,
                'position_id' => $positionId,
                'effective_date' => $effectiveDate,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        EmployeePosition::query()->where('employee_id', $employeeId)->delete();

        if ($rows !== []) {
            EmployeePosition::query()->insert($rows);
        }
    }

    private function replacePayHistories(int $employeeId, array $payload): void
    {
        $now = now();
        $rows = [];

        foreach ($this->relation($payload, 'pay_histories', 'payHistories') as $row) {
            if (!is_array($row)) {
                continue;
            }

            $basePay = data_get($row, 'base_pay');
            $effectiveDate = $this->asDate(data_get($row, 'effective_date'));

            // No rate is not a rate of zero: a 0.00 row would silently cost this
            // employee's shifts at nothing. Drop it and let the query find none.
            if (!is_numeric($basePay) || $effectiveDate === null) {
                continue;
            }

            $performancePay = data_get($row, 'performance_pay');

            $rows[] = [
                'employee_id' => $employeeId,
                'base_pay' => (float) $basePay,
                'performance_pay' => is_numeric($performancePay) ? (float) $performancePay : 0.0,
                'effective_date' => $effectiveDate,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        EmployeePayHistory::query()->where('employee_id', $employeeId)->delete();

        if ($rows !== []) {
            EmployeePayHistory::query()->insert($rows);
        }
    }

    /**
     * Flatten availability_days x times into one window row each, carrying
     * day_of_week and shift_type down from the day onto every one of its times.
     * A day with no times produces nothing — inventing a window would tell the
     * scheduler someone is free when hiring never said so.
     */
    private function replaceAvailabilityWindows(int $employeeId, array $payload): void
    {
        $now = now();
        $rows = [];
        $seen = [];

        foreach ($this->relation($payload, 'availability_days', 'availabilityDays') as $day) {
            if (!is_array($day)) {
                continue;
            }

            $dayOfWeek = $this->enumValue(data_get($day, 'day_of_week'), DayOfWeek::class);
            if ($dayOfWeek === null) {
                continue;
            }

            $shiftType = $this->enumValue(data_get($day, 'shift_type'), AvailabilityShiftType::class);

            $times = data_get($day, 'times');
            if (!is_array($times)) {
                continue;
            }

            foreach ($times as $time) {
                if (!is_array($time)) {
                    continue;
                }

                $from = $this->asTime(data_get($time, 'available_from'));
                $to = $this->asTime(data_get($time, 'available_to'));

                // Equal endpoints are ambiguous between zero-length and 24 hours
                // and are rejected by a CHECK on MySQL, so drop them here too.
                if ($from === null || $to === null || $from === $to) {
                    continue;
                }

                $key = $dayOfWeek . '|' . $from . '|' . $to;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $rows[] = [
                    'employee_id' => $employeeId,
                    'day_of_week' => $dayOfWeek,
                    'available_from' => $from,
                    'available_to' => $to,
                    'shift_type' => $shiftType,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        EmployeeAvailabilityWindow::query()->where('employee_id', $employeeId)->delete();

        if ($rows !== []) {
            EmployeeAvailabilityWindow::query()->insert($rows);
        }
    }

    // ----------------------------------------------------------------- helpers

    private function storeExists(int $storeId): bool
    {
        return Store::query()->whereKey($storeId)->exists();
    }

    private function positionExists(int $positionId): bool
    {
        return Position::query()->whereKey($positionId)->exists();
    }

    /**
     * The row with the latest effective_date, ties broken by the highest id.
     * Rows with no usable effective_date cannot be ordered and are ignored.
     */
    private function latestByEffectiveDate(array $rows): ?array
    {
        $best = null;
        $bestKey = null;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = $this->asDate(data_get($row, 'effective_date'));
            if ($date === null) {
                continue;
            }

            $key = [$date, $this->asInt(data_get($row, 'id'))];

            if ($bestKey === null || $key > $bestKey) {
                $bestKey = $key;
                $best = $row;
            }
        }

        return $best;
    }

    private function enumValue(mixed $value, string $enumClass): ?string
    {
        if ($value instanceof $enumClass) {
            return $value->value;
        }

        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        return $enumClass::tryFrom((string) $value)?->value;
    }

    private function asDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function asDateTime(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** Normalises 'H:i' / 'H:i:s' / an ISO datetime down to a wall-clock 'H:i:s'. */
    private function asTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m) === 1) {
            $hours = (int) $m[1];
            $minutes = (int) $m[2];
            $seconds = (int) ($m[3] ?? 0);

            if ($hours > 23 || $minutes > 59 || $seconds > 59) {
                return null;
            }

            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        try {
            return Carbon::parse($value)->format('H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function asBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    private function trimmedString(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function clip(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
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
