{{-- One punch in a week cell — the ACTUAL side of the grid.
     $g = WorkSegment (with employee, position), plus $bd and $storeId from the
     parent view.

     THREE STATES, AND THE DIFFERENCE MATTERS MORE THAN THE TIMES DO:

       open      clocked in, no clock-out. Somebody is in the store right now.
                 There are no hours yet, so there is nothing to approve — the
                 service refuses it, and the chip does not offer it.
       pending   a finished punch nobody has signed off. This is the work.
       approved  signed off, and pushed to TCP as an approval. --}}
@php
    $open = $g->time_out === null;
    $approved = (bool) $g->manager_approval;

    $inLocal = $bd->toLocal($storeId, $g->time_in);
    $outLocal = $open ? null : $bd->toLocal($storeId, $g->time_out);

    $businessDate = $g->business_date?->toDateString();

    // A punch that ran past midnight clocks out on the NEXT local day. Marked
    // with a ⁺ rather than left bare: 17:00–02:00 is an eight-hour night, and
    // unmarked it reads as fifteen hours of nonsense.
    $crossed = $outLocal !== null && $outLocal->toDateString() !== $inLocal->toDateString();

    $state = match (true) {
        $open => 'open',
        $approved => 'approved',
        default => 'pending',
    };

    $tip = ['Punch #'.$g->id.($g->position ? ' · '.$g->position->label : '')];

    $tip[] = $open
        ? 'Clocked in at '.$inLocal->format('H:i').', still in the store. No hours to approve until they clock out.'
        : number_format((float) $g->hours, 2).' h'
            .((int) $g->break_minutes > 0 ? ' (after a '.$g->break_minutes.' min break)' : '')
            .' · '.($approved ? 'approved' : 'NOT approved yet');

    if ($g->shift_id === null) {
        $tip[] = 'No planned shift behind these hours.';
    }

    $tip[] = 'TCP '.($g->tcp_segment_id ? '#'.$g->tcp_segment_id : 'id not issued yet')
        .' · '.$g->tcp_sync_state?->label();
@endphp
<div class="chip-seg {{ $state }}"
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
     data-update-url="{{ route('board.segments.update', $g) }}"
     data-delete-url="{{ route('board.segments.destroy', $g) }}"
     title="{{ implode("\n", $tip) }}">
  <span class="t">
    @if ($open)
      {{ $inLocal->format('H:i') }} → <em style="font-style:normal">still in</em>
    @else
      {{ $inLocal->format('H:i') }}–{{ $outLocal->format('H:i') }}{{ $crossed ? '⁺' : '' }}
    @endif
  </span>
  <span class="m">
    <span>
      #{{ $g->id }}
      @if (! $open) · {{ number_format((float) $g->hours, 2) }}h @endif
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
