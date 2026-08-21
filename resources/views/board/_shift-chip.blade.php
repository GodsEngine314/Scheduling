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
        $locked => 'published — use "Unpublish all" above to unlock the week, then edit, move or delete',
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

    // What deleting this chip will actually do, said before it happens rather
    // than in a flash message afterwards. A soft delete is recoverable only from
    // the database, so the confirm is the last honest chance to stop.
    $delLines = ['Delete shift #'.$s->id.' ('.$hhmm($s->start_at).'–'.$hhmm($s->end_at).')?'];

    if ($s->series_id !== null) {
        // The default rule is 'following', which reaches dates the manager is
        // not looking at. That has to be said out loud.
        $delLines[] = 'It belongs to a recurring series, so THIS occurrence and every later one go with it.';
    }

    // The ID, not the state: a row that failed mid-publish keeps its
    // humanity_shift_id and is still held there, however it reads on the board.
    if ($s->humanity_shift_id !== null) {
        $delLines[] = 'Humanity is holding this shift and it will be withdrawn from there too, '
            .'so nobody stays rostered for it.';
    }

    if ($worked > 0) {
        $delLines[] = $worked.' punch(es) keep pointing at it, so the hours worked are not lost.';
    }

    $delConfirm = implode("\n\n", $delLines);
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
  {{-- DELETE ONLY, AND ONLY WHEN UNLOCKED.

       There used to be a 🔓 here for a published shift, and it was the wrong
       grain: unpublishing exists to serve "unlock the week, change it, republish",
       so doing it a chip at a time meant fourteen clicks before a manager could
       touch anything. It is a range action now — see
       board/_range-actions.blade.php — and a locked chip simply carries no
       button, with its 🔒 and its tooltip saying where the unlock went.

       A plain form, not the fetch the drag handlers use: a redirect puts the
       outcome in the page's own flash banner — including whether Humanity was
       told — and that is a sentence worth reading rather than a toast. --}}
  @unless ($locked)
    <form method="POST" action="{{ route('board.shifts.destroy', $s) }}" class="chip-del-form">
      @csrf
      @method('DELETE')
      {{-- Stated rather than left to the controller's default, so what the confirm
           above promises and what the server does cannot drift apart. --}}
      <input type="hidden" name="rule" value="following">
      <button type="submit" class="chip-del"
              {{-- The chip is draggable and the button lives inside it. Without
                   this, grabbing the × starts dragging the shift. --}}
              draggable="false"
              data-confirm="{{ $delConfirm }}"
              aria-label="Delete shift #{{ $s->id }}"
              title="Delete this shift">×</button>
    </form>
  @endunless
</div>
