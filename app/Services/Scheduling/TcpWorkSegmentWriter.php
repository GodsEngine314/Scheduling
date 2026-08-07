<?php

namespace App\Services\Scheduling;

use App\Enums\TcpSyncState;
use App\Exceptions\IntegrationException;
use App\Models\WorkSegment;
use App\Support\Integrations\Tcp\TcpClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes our version of a work segment to TCP.
 *
 * The document's three edit workflows all land on the same two endpoints:
 *
 *   Approving Hours   PUT  /worksegments/{id}   with managerApproval
 *   Change Shift      PUT  /worksegments/{id}   with timeIn / timeOut
 *   Create Shift      POST /worksegments        the "forgot to clock in" case
 *   Deleting Shifts   DEL  /worksegments/{id}
 *
 * so one writer covers all of them and the caller only says "this row changed".
 *
 * ORDERING: the local row is always written first and this runs afterwards,
 * from a queued job. A TCP outage must never stop a manager approving hours —
 * the day close is gated on approvals, so a blocking write would take the whole
 * store down with the vendor. The cost is a window where local and TCP
 * disagree, which is exactly what tcp_sync_state exists to make visible.
 *
 * FIELD NAMES ARE UNCONFIRMED. Figures 16-19 of the source document are images
 * that could not be read, so the wire keys below are inferred from the prose
 * ("with parameters of timeIn and timeOut") and from convention. They are all
 * in wireBody() and nowhere else, so correcting them once a real request has
 * been captured is a single-method change.
 */
class TcpWorkSegmentWriter
{
    public function __construct(private readonly TcpClient $tcp) {}

    /**
     * Create in TCP if it has no id yet, otherwise update.
     *
     * @return WorkSegment the same row, with its sync columns updated
     */
    public function push(WorkSegment $segment): WorkSegment
    {
        try {
            $response = $segment->tcp_segment_id === null
                ? $this->create($segment)
                : $this->update($segment);

            return $this->markSynced($segment, $response);
        } catch (IntegrationException $e) {
            return $this->markFailed($segment, $e);
        }
    }

    /**
     * A locally-created segment TCP has never seen.
     *
     * @return array<mixed>
     */
    private function create(WorkSegment $segment): array
    {
        $response = $this->tcp->createWorkSegment($this->wireBody($segment));

        // The id TCP hands back is the whole point of the call: without it the
        // next edit would create a SECOND segment rather than updating this one.
        $id = $this->extractId($response);

        if ($id !== null) {
            $segment->tcp_segment_id = $id;
        }

        return $response;
    }

    /** @return array<mixed> */
    private function update(WorkSegment $segment): array
    {
        return $this->tcp->updateWorkSegment($segment->tcp_segment_id, $this->wireBody($segment));
    }

    /**
     * Remove from TCP. Called after the local soft delete, so a failure here
     * leaves a row that is gone locally and present in TCP — reported, not
     * hidden, because the alternative is refusing to let anyone delete anything
     * while the vendor is down.
     */
    public function delete(WorkSegment $segment): bool
    {
        if ($segment->tcp_segment_id === null) {
            // Never reached TCP, so there is nothing to remove.
            return true;
        }

        try {
            $this->tcp->deleteWorkSegment($segment->tcp_segment_id);

            $segment->forceFill([
                'tcp_sync_state' => TcpSyncState::Synced,
                'tcp_synced_at' => now(),
                'tcp_sync_error' => null,
            ])->saveQuietly();

            return true;
        } catch (IntegrationException $e) {
            $this->markFailed($segment, $e);

            return false;
        }
    }

    /**
     * OUR row in TCP's field names.
     *
     * Every key here is a GUESS — see the class docblock. Nulls are stripped,
     * with one deliberate exception: timeOut is sent even when null, because
     * null IS the value for an open punch and dropping it would silently fail
     * to reopen a segment that was clocked out by mistake.
     *
     * @return array<string,mixed>
     */
    private function wireBody(WorkSegment $segment): array
    {
        // The TCP ids come from integration_identities first and only fall
        // back to the projected column. An id WE obtained by calling TCP must
        // not be read off a projection, because a rebuild erases it there.
        $identity = $segment->employee === null
            ? null
            : TcpEmployeeWriter::resolve($segment->employee);

        $body = array_filter([
            'employeeId' => $identity['external_id'] ?? null,
            'employeeRecordId' => $identity['external_record_id'] ?? null,
            'timeIn' => $segment->time_in?->toIso8601String(),
            'breakTime' => $segment->break_minutes ?: null,
            'costCodeName' => $segment->cost_code_name,
            'laborCode' => $segment->labor_code,
            'notes' => $segment->notes,
            'managerApproval' => $segment->manager_approval ?: null,
            'employeeApproval' => $segment->employee_approval ?: null,
        ], static fn (mixed $v): bool => $v !== null);

        // Sent unconditionally, including as null. See above.
        $body['timeOut'] = $segment->time_out?->toIso8601String();

        return $body;
    }

    /** @param  array<mixed>  $response */
    private function markSynced(WorkSegment $segment, array $response): WorkSegment
    {
        $segment->forceFill([
            'tcp_sync_state' => TcpSyncState::Synced,
            'tcp_synced_at' => now(),
            'tcp_sync_error' => null,
            'tcp_sync_attempts' => 0,
            // Keep the raw response while the field mapping is unconfirmed.
            'tcp_payload' => $response,
        ])->saveQuietly();

        return $segment;
    }

    private function markFailed(WorkSegment $segment, IntegrationException $e): WorkSegment
    {
        $segment->forceFill([
            'tcp_sync_state' => TcpSyncState::Failed,
            'tcp_sync_attempts' => (int) $segment->tcp_sync_attempts + 1,
            'tcp_sync_error' => $e->getMessage(),
        ])->saveQuietly();

        Log::warning('TCP work segment push failed', [
            'work_segment_id' => $segment->id,
            'tcp_segment_id' => $segment->tcp_segment_id,
            'transient' => $e->isTransient(),
            'error' => $e->getMessage(),
        ]);

        // Rethrow only what is worth retrying. A 4xx will be rejected
        // identically next time, so burning queue attempts on it is waste.
        if ($e->isTransient()) {
            throw $e;
        }

        return $segment;
    }

    /** @param  array<mixed>  $response */
    private function extractId(array $response): ?string
    {
        // Same defensive posture as TcpClient::records(): no live response has
        // been seen, so accept the spellings vendors actually use.
        foreach (['id', 'workSegmentId', 'worksegmentId', 'segmentId'] as $key) {
            $value = data_get($response, $key) ?? data_get($response, "0.{$key}") ?? data_get($response, "data.{$key}");

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        try {
            Log::warning('TCP create returned no recognisable segment id', [
                'keys' => array_keys($response),
            ]);
        } catch (Throwable) {
            // Logging must never break the write it is reporting on.
        }

        return null;
    }
}
