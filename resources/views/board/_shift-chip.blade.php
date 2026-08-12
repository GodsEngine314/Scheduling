{{-- One draggable shift in a week cell.
     $s = Shift (loaded withCount('workSegments')), $hhmm = store-local H:i --}}
@php
    // work_segments_count comes from withCount in the controller. Calling
    // ->workSegments()->count() here would be one query per chip.
    $worked = (int) ($s->work_segments_count ?? 0);
    $bad = $s->availability_check?->value === 'outside_availability';
    $locked = (bool) $s->publish_state?->isLocked();

    // Two different reasons a chip cannot be dragged, and the manager needs to
    // know which: one is fixed by unpublishing, the other by removing a punch.
    $why = match (true) {
        $locked => 'published — unpublish it on the day view to edit or move it',
        $worked > 0 => $worked.' punch(es) reconciled — it can only be copied',
        default => null,
    };

    // Built here rather than with @if inside the attribute: Blade directives
    // interleaved with quoted HTML attribute text do not survive compilation.
    $tip = ['Shift #'.$s->id.($s->position ? ' · '.$s->position->label : '')];
    $tip[] = $s->paidHours().' paid hours · '.$s->publish_state?->label();
    if ($bad) {
        $tip[] = "Outside this employee's availability";
    }
    if ($why !== null) {
        $tip[] = $why;
    }
@endphp
<div class="chip-shift {{ $bad ? 'bad' : '' }} {{ ($worked || $locked) ? 'locked' : '' }} {{ $locked ? 'published' : '' }}"
     draggable="{{ $locked ? 'false' : 'true' }}"
     data-shift="{{ $s->id }}"
     data-worked="{{ $worked }}"
     {{-- Only a PUBLISHED shift refuses the drag outright. A worked one is
          still copyable, so it stays draggable and the drop handler decides. --}}
     data-locked="{{ $locked ? 1 : 0 }}"
     data-why="{{ $why }}"
     title="{{ implode("\n", $tip) }}">
  <span class="t">{{ $hhmm($s->start_at) }}–{{ $hhmm($s->end_at) }}</span>
  <span class="m">
    #{{ $s->id }}@if ($s->isSplit()) p{{ $s->split_part }}@endif
    @if ($worked) ⏱{{ $worked }} @endif
    @if ($locked) 🔒 @elseif ($s->publish_state?->isLive()) ✎ @endif
  </span>
</div>
