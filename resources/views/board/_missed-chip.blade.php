{{-- A planned shift on a day that has ENDED, with no punch against it at all.
     $s = Shift (with work_segments_count), plus $bd, $storeId from the parent.

     A MISSED CLOCK-IN, and it is the one gap the actual grid cannot show from
     the punches — because there is no punch. Without this the cell is simply
     empty, which reads as "not scheduled" rather than "scheduled, and nobody
     recorded turning up".

     Deliberately NOT rendered for today or any future day: a shift that has not
     happened yet has no punch for the obvious reason, and flagging it would
     paint most of the grid amber every Monday morning.

     It is a REPORT, not a record. There is nothing here to approve or correct —
     the fix is either a punch that never synced from TCP, or a correction filed
     against the day on the day board. --}}
@php
    $startLocal = $bd->toLocal($storeId, $s->start_at);
    $endLocal = $bd->toLocal($storeId, $s->end_at);

    $tip = [
        'MISSED CLOCK-IN. Shift #'.$s->id.' was planned '
            .$startLocal->format('H:i').'–'.$endLocal->format('H:i')
            .($s->position ? ' as '.$s->position->label : '')
            .', and no punch was ever recorded against it.',
        'Either TCP has hours we have not pulled, or nobody clocked in. '
            .'Pull the week again first; if it stays empty, it needs a correction.',
    ];
@endphp
<div class="chip-seg missed missing-in" title="{{ implode("\n", $tip) }}">
  <span class="t">⚠ {{ $startLocal->format('H:i') }}–{{ $endLocal->format('H:i') }}</span>
  <span class="m"><span>no punch</span></span>
</div>
