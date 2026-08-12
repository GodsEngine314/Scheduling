<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\ActivitySubject;
use App\Models\ActivityLog;
use App\Models\Shift;
use App\Models\WorkSegment;
use App\Support\ActingUser;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place an activity_logs row is written.
 *
 * Called from the DOMAIN SERVICES rather than the controllers, so the JSON API
 * is audited on the same terms as the console. A controller-level hook would
 * have covered the board and quietly missed every API caller.
 *
 * HiringPizza puts a private createAuditLog() helper on each service; this is
 * the same idea with one write path instead of four, which matters more here
 * because four services record against three different subject types.
 *
 * LOGGING MUST NEVER BREAK THE THING IT RECORDS. A failure here degrades to the
 * application log: losing an audit line is bad, but rolling back a manager's
 * approval because the audit insert failed is worse.
 */
class ActivityLogger
{
    public function __construct(private readonly ActingUser $actingUser) {}

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     * @param  array<string, mixed>  $context
     */
    public function record(
        ActivitySubject $subject,
        ActivityAction $action,
        ?int $subjectId = null,
        ?int $storeId = null,
        CarbonInterface|string|null $businessDate = null,
        array $changes = [],
        array $context = [],
    ): ?ActivityLog {
        try {
            return ActivityLog::query()->create([
                'user_id' => $this->actingUser->id(),
                // Copied, not joined: the FK is nullOnDelete because users is a
                // projection, and an unattributable audit row is worthless.
                'actor_name' => $this->actingUser->name(),
                'store_id' => $storeId,
                'subject_type' => $subject,
                'subject_id' => $subjectId,
                'action' => $action,
                'business_date' => $this->dateString($businessDate),
                'changed_fields' => $changes === [] ? null : $changes,
                'context' => $context === [] ? null : $context,
            ]);
        } catch (Throwable $e) {
            Log::warning('Activity log write failed', [
                'subject' => $subject->value,
                'subject_id' => $subjectId,
                'action' => $action->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** A shift action, with store and business_date filled in from the row. */
    public function shift(
        Shift $shift,
        ActivityAction $action,
        array $changes = [],
        array $context = [],
    ): ?ActivityLog {
        return $this->record(
            ActivitySubject::Shift,
            $action,
            (int) $shift->id,
            $shift->store_id === null ? null : (int) $shift->store_id,
            $shift->business_date,
            $changes,
            $context,
        );
    }

    public function workSegment(
        WorkSegment $segment,
        ActivityAction $action,
        array $changes = [],
        array $context = [],
    ): ?ActivityLog {
        return $this->record(
            ActivitySubject::WorkSegment,
            $action,
            (int) $segment->id,
            $segment->store_id === null ? null : (int) $segment->store_id,
            $segment->business_date,
            $changes,
            $context,
        );
    }

    /**
     * The diff between a model's original and current attributes.
     *
     * Call BEFORE save() while the dirty set still describes the edit, and pass
     * the result in. Only keys that actually moved are returned — a log line
     * listing twelve unchanged fields hides the one that mattered.
     *
     * @param  array<int, string>  $fields
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diff(Model $model, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            if (! $model->isDirty($field)) {
                continue;
            }

            $changes[$field] = [
                'from' => $this->scalar($model->getOriginal($field)),
                'to' => $this->scalar($model->getAttribute($field)),
            ];
        }

        return $changes;
    }

    /** JSON-safe: enums, Carbon and models must not land in the column raw. */
    private function scalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof CarbonInterface => $value->toIso8601String(),
            $value instanceof Model => $value->getKey(),
            is_scalar($value), $value === null, is_array($value) => $value,
            default => (string) $value,
        };
    }

    private function dateString(CarbonInterface|string|null $date): ?string
    {
        return match (true) {
            $date === null => null,
            $date instanceof CarbonInterface => $date->toDateString(),
            default => $date,
        };
    }
}
