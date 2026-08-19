<?php

namespace App\Services\Scheduling;

use App\Enums\TcpSyncState;
use App\Exceptions\IntegrationException;
use App\Models\TcpJobCodeRole;
use App\Models\WorkSegment;
use App\Support\BusinessDay;
use App\Support\Integrations\Tcp\TcpClient;
use Carbon\CarbonInterface;
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
 * TIME CONTRACT: timeIn / timeOut go out as STORE-LOCAL WALL CLOCK, because
 * that is what TCP's own timeclock writes and what its UI and payroll read. Our
 * columns are UTC instants, so every outbound time passes through wireTime() —
 * see the reasoning there, and note that it is confirmed against a live punch
 * rather than inferred like the key names below.
 *
 * FIELD NAMES ARE UNCONFIRMED. Figures 16-19 of the source document are images
 * that could not be read, so the wire keys below are inferred from the prose
 * ("with parameters of timeIn and timeOut") and from convention. They are all
 * in wireBody() and nowhere else, so correcting them once a real request has
 * been captured is a single-method change.
 */
class TcpWorkSegmentWriter
{
    public function __construct(
        private readonly TcpClient $tcp,
        private readonly BusinessDay $businessDay,
    ) {}

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
        // REFUSED HERE RATHER THAN BY TCP. A create without a job code comes
        // back 400 "The jobCodeId must have a value." — a round trip spent to
        // be told something we already knew, landing in tcp_sync_error as a
        // vendor complaint about a field no manager has heard of rather than as
        // the thing they can actually fix: this punch has no position on it.
        //
        // ON THE CREATE PATH ONLY, and the asymmetry is deliberate. The 400 is
        // confirmed for POST and NOTHING IS KNOWN ABOUT PUT. Guarding updates
        // too would be a guess with an expensive failure mode: approving hours
        // is an update, the day close is gated on approvals, and refusing them
        // at a store whose number cannot form a code would take that store's
        // close down for a field TCP may not even want here.
        if (TcpJobCodeRole::jobCodeIdFor(
            $segment->store?->store_number,
            $segment->position_id === null ? null : (int) $segment->position_id,
        ) === null) {
            throw IntegrationException::guard(
                'tcp',
                'POST /worksegments',
                $this->explainMissingJobCode($segment),
            );
        }

        // WHO WORKED IT. array_filter drops these when they are null, so a
        // punch for somebody TCP has never heard of would go out as a body with
        // times and no person on it — which TCP rejects with a message about a
        // field, when the fixable truth is that this employee has no TCP
        // mapping yet. Four of the estate's employees are in that state.
        $identity = $segment->employee === null
            ? null
            : TcpEmployeeWriter::resolve($segment->employee);

        if (($identity['external_id'] ?? null) === null) {
            throw IntegrationException::guard(
                'tcp',
                'POST /worksegments',
                $segment->employee === null
                    ? 'This punch has no employee on it, so there is nobody to file it against at TCP.'
                    : $segment->employee->fullName().' has no TCP employee id yet, so TCP cannot be told whose hours these are. '
                        .'Pull the roster from TCP for this store, or check that this person exists there.',
            );
        }

        $response = $this->tcp->createWorkSegment($this->wireBody($segment));

        // The id TCP hands back is the whole point of the call: without it the
        // next edit would create a SECOND segment rather than updating this one.
        $id = $this->extractId($response);

        if ($id !== null) {
            $segment->tcp_segment_id = $id;
        }

        return $response;
    }

    /**
     * WHY this punch has no job code, in terms the person who made it can act
     * on.
     *
     * Three genuinely different faults reach the same dead end, and telling
     * them apart is the difference between a fixable message and a shrug:
     *
     *   no position          pick one — the commonest, since the hand-entry
     *                        form lets a punch be saved without one
     *   position unmapped    Driver and Insider are ours, not TCP's; no job
     *                        code anywhere corresponds to them
     *   role not at store    Management exists at exactly one store in the
     *                        estate, so it cannot be worked at the others
     */
    private function explainMissingJobCode(WorkSegment $segment): string
    {
        $storeNumber = $segment->store?->store_number;

        if ($segment->position_id === null) {
            return 'This punch has no position on it, and TCP will not take hours without one — the job code it requires says which role was worked.';
        }

        if (TcpJobCodeRole::storeKeyFor($storeNumber) === null) {
            return 'Store '.($storeNumber ?? 'unknown').' has no TCP job codes, because its store number is not in the franchise-store form (03795-00010) that a job code is built from.';
        }

        $label = $segment->position?->label ?? 'That position';

        if (! TcpJobCodeRole::query()->where('position_id', $segment->position_id)->exists()) {
            return $label.' is not a TCP role, so no job code corresponds to it. Use one of the positions TCP knows about.';
        }

        return 'TCP has no '.$label.' job code at store '.($storeNumber ?? 'unknown')
            .'. That role exists at some stores and not others, so the hours cannot be filed under it here.';
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
            // CONFIRMED BY THE VENDOR, unlike its neighbours: a POST without
            // this comes back 400 "The jobCodeId must have a value." It encodes
            // franchise, store and role in one number, so it is derived from
            // both the segment's store and its position rather than stored.
            'jobCodeId' => TcpJobCodeRole::jobCodeIdFor(
                $segment->store?->store_number,
                $segment->position_id === null ? null : (int) $segment->position_id,
            ),
            'timeIn' => $this->wireTime($segment, $segment->time_in),
            'breakTime' => $segment->break_minutes ?: null,
            'costCodeName' => $segment->cost_code_name,
            'laborCode' => $segment->labor_code,
            'notes' => $segment->notes,
            'managerApproval' => $segment->manager_approval ?: null,
            'employeeApproval' => $segment->employee_approval ?: null,
        ], static fn (mixed $v): bool => $v !== null);

        // Sent unconditionally, including as null. See above.
        $body['timeOut'] = $this->wireTime($segment, $segment->time_out);

        return $body;
    }

    /**
     * A UTC instant as TCP wants a punch time: STORE-LOCAL WALL CLOCK, bare.
     *
     * NOT A COSMETIC CHOICE, and confirmed against a real timeclock punch
     * rather than guessed like the key names around it:
     *
     *   "timeIn":             "2026-08-17T17:59:00"   <- local, no offset
     *   "createdOnDateTime":  "2026-08-17T21:59:00"   <- the same instant, UTC
     *
     * A standalone clock creates a segment the moment somebody badges in, so
     * those two fields describe one instant — and 17:59 in America/New_York IS
     * 21:59 UTC. TCP therefore keeps punch times on the store's wall and its own
     * bookkeeping timestamps in UTC, which is exactly the split the reader
     * already assumes (WorkSegmentSyncService::instant vs ::parseUtc).
     *
     * This method used to be ->toIso8601String(), which sent the UTC instant
     * with a +00:00 on it, and TCP stored the string verbatim — the echo came
     * back "2026-08-11T21:00:00+00:00" for hours worked at 17:00. Every punch we
     * wrote landed in TCP the store's offset LATE: four hours in Ohio, five in
     * the Chicago stores, six in Denver. It did not show up on our own board,
     * because the offset in the string means the pull reads it back correctly —
     * only TCP, and payroll behind it, saw the wrong time.
     *
     * The write path that matters most is not the hand-entered punch: approving
     * a punch PULLED from the timeclock PUTs this whole body back, so an
     * approval was rewriting a correct vendor record with a shifted one.
     *
     * The store, not the employee, owns the zone — one punch happens at one
     * store, and store_settings.timezone is where the estate's zones live. TCP
     * can read it the same way: jobCodeId encodes the store.
     */
    private function wireTime(WorkSegment $segment, ?CarbonInterface $instant): ?string
    {
        if ($instant === null) {
            return null;
        }

        return $this->businessDay
            ->toLocal((int) $segment->store_id, $instant)
            ->format('Y-m-d\TH:i:s');
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
        // THE VENDOR'S OWN WORDS, not just our summary of them.
        //
        // getMessage() is deliberately body-free — see IntegrationException,
        // which keeps vendor echoes out of anything Laravel's handler writes to
        // a log file. That is right for the log and wrong here. EVERY WIRE KEY
        // IN wireBody() IS A GUESS (see the class docblock), so "HTTP 400" on
        // its own is unactionable: it says the payload was wrong without saying
        // which part, which is the only question worth asking.
        //
        // This column is a row on the segment, not a log line, and the row
        // already carries tcp_payload for exactly this reason — keeping the raw
        // vendor response while the mapping is unconfirmed. The excerpt is
        // capped at 1000 characters by the exception itself.
        $segment->forceFill([
            'tcp_sync_state' => TcpSyncState::Failed,
            'tcp_sync_attempts' => (int) $segment->tcp_sync_attempts + 1,
            'tcp_sync_error' => $e->responseExcerpt === null
                ? $e->getMessage()
                : $e->getMessage().' Response: '.$e->responseExcerpt,
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
        // CONFIRMED AGAINST A LIVE CREATE, which the guesses below predate:
        //
        //   {"data":[{"id":"17727488","jobCodeId":"37951001",...}],"errors":[]}
        //
        // createWorkSegment() posts a repeatable body, so `data` comes back a
        // LIST and the id sits at data.0.id. That path was the one spelling not
        // tried here, and missing it is not a cosmetic failure: the row was
        // marked synced with tcp_segment_id still null, so the NEXT edit would
        // have taken the create branch again and put a second segment in TCP
        // for the same worked hours — a duplicate on somebody's paycheque,
        // which is the exact thing this method exists to prevent.
        //
        // The other spellings are kept: they cost nothing and no other write
        // path here has been seen live yet.
        foreach (['id', 'workSegmentId', 'worksegmentId', 'segmentId'] as $key) {
            $value = data_get($response, "data.0.{$key}")
                ?? data_get($response, $key)
                ?? data_get($response, "0.{$key}")
                ?? data_get($response, "data.{$key}");

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
