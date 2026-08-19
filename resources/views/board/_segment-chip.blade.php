{{-- One punch in a week cell — the ACTUAL side of the grid.
     $g = WorkSegment (with employee, position), plus $bd, $storeId and $today
     from the parent view.

     THE COLOUR ENCODES WHETHER THE PUNCH IS WHOLE, because that is the thing
     you scan a week for. Approval is a separate, smaller mark: a signed-off
     punch and an unsigned one are both real records of worked hours, whereas a
     punch missing half of itself is a hole in the timesheet.

       done      in and out. Green. The ordinary case, and most of the grid.
       open      clocked in TODAY and not out yet — somebody is in the store
                 right now. Shows only the in time, because there is no out.
       missed    clocked in on a day that has since ENDED and never clocked out.
                 Same missing field as `open`, completely different meaning: this
                 one is a hole somebody has to correct. Amber, and marked ⚠.

     Both `open` and `missed` are "time_out IS NULL". Only the date tells them
     apart, and it has to be the STORE's date — see $today in BoardController. --}}
@php
    $open = $g->time_out === null;
    $approved = (bool) $g->manager_approval;

    $inLocal = $bd->toLocal($storeId, $g->time_in);
    $outLocal = $open ? null : $bd->toLocal($storeId, $g->time_out);

    $businessDate = $g->business_date?->toDateString();

    // The day this punch belongs to is over, so nobody is coming back to clock
    // out of it.
    $missedOut = $open && $businessDate !== null && $businessDate < $today;
    $stillIn = $open && ! $missedOut;

    // A punch that ran past midnight clocks out on the NEXT local day. Marked
    // with a ⁺ rather than left bare: 17:00–02:00 is an eight-hour night, and
    // unmarked it reads as fifteen hours of nonsense.
    $crossed = $outLocal !== null && $outLocal->toDateString() !== $inLocal->toDateString();

    $state = match (true) {
        $missedOut => 'missed',
        $stillIn => 'open',
        default => 'done',
    };

    $tip = ['Punch #'.$g->id.($g->position ? ' · '.$g->position->label : '')];

    $tip[] = match (true) {
        $missedOut => 'MISSED CLOCK-OUT. Clocked in at '.$inLocal->format('H:i')
            .' on '.$businessDate.' and never clocked out, and that day has ended.'
            .' Correct the times to close it — there are no hours until you do.',
        $stillIn => 'Clocked in at '.$inLocal->format('H:i').', still in the store.'
            .' No hours to approve until they clock out.',
        default => number_format((float) $g->hours, 2).' h'
            .((int) $g->break_minutes > 0 ? ' (after a '.$g->break_minutes.' min break)' : '')
            .' · '.($approved ? 'approved' : 'NOT approved yet'),
    };

    if ($g->shift_id === null) {
        $tip[] = 'No planned shift behind these hours.';
    }

    $tip[] = 'TCP '.($g->tcp_segment_id ? '#'.$g->tcp_segment_id : 'id not issued yet')
        .' · '.$g->tcp_sync_state?->label();

    // WHY it failed, not just that it did. "failed" on its own sends somebody
    // to the logs; the sentence beside it is usually enough to fix the punch
    // from this screen — most often that its position has no TCP job code.
    if ($g->tcp_sync_state === \App\Enums\TcpSyncState::Failed && $g->tcp_sync_error) {
        $tip[] = \Illuminate\Support\Str::limit($g->tcp_sync_error, 220);
    }
@endphp
<div class="chip-seg {{ $state }} {{ $approved ? 'is-approved' : '' }}"
     data-seg="{{ $g->id }}"
     data-who="{{ $g->employee?->fullName() }}"
     data-date="{{ $businessDate }}"
     data-in="{{ $inLocal->format('H:i') }}"
     data-out="{{ $outLocal?->format('H:i') }}"
     data-open="{{ $open ? 1 : 0 }}"
     data-approved="{{ $approved ? 1 : 0 }}"
     data-hours="{{ $open ? '' : number_format((float) $g->hours, 2) }}"
     {{-- TCP's break, not ours. The dialog subtracts it when it previews the
          paid hours a correction will produce, exactly as the service does. --}}
     data-break="{{ (int) $g->break_minutes }}"
     data-tcp="{{ $g->tcp_segment_id }}"
     {{-- What TCP will file these hours as. The dialog preselects it so a plain
          time correction cannot silently re-file the punch under another role. --}}
     data-position="{{ $g->position_id }}"
     data-update-url="{{ route('board.segments.update', $g) }}"
     data-delete-url="{{ route('board.segments.destroy', $g) }}"
     title="{{ implode("\n", $tip) }}">
  <span class="t">
    @if ($missedOut)
      ⚠ {{ $inLocal->format('H:i') }} → <em style="font-style:normal">no out</em>
    @elseif ($stillIn)
      {{ $inLocal->format('H:i') }} → <em style="font-style:normal">still in</em>
    @else
      {{ $inLocal->format('H:i') }}–{{ $outLocal->format('H:i') }}{{ $crossed ? '⁺' : '' }}
    @endif
  </span>
  <span class="m">
    <span>
      @if ($missedOut)
        missed out
      @else
        #{{ $g->id }}
        @if (! $open) · {{ number_format((float) $g->hours, 2) }}h @endif
        {{-- Approval, demoted to a mark now that the background carries whether
             the punch is whole. Only meaningful on a finished punch. --}}
        @if (! $open && $approved) · ✓ @endif
      @endif
      @if ($g->shift_id === null) ⚠ @endif
    </span>
    <span class="acts">
      @if (! $open && ! $approved)
        {{-- One click, one employee. There is no approve-all anywhere on this
             console by design: each person's hours are signed off on purpose. --}}
        <form method="POST" action="{{ route('board.segments.approve', $g) }}" class="inline">
          @csrf<button class="mini" title="Approve these hours and push the approval to TCP">✓</button>
        </form>
      @endif
      <button type="button" class="mini seg-edit" title="Correct the times, or delete this punch">⋯</button>
    </span>
  </span>
</div>
