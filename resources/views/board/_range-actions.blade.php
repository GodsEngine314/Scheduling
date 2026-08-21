{{-- Clearing a WHOLE RANGE of shifts.
     $storeId, $from, $to, $label, $range (a ShiftRangeService summary).

     REPLACED A PER-CHIP CONTROL: the workflow is week-sized and the button was
     shift-sized, so clearing a week meant fourteen confirms.

     UNPUBLISH USED TO LIVE HERE TOO and has moved to the Humanity card, which is
     now a single button cycling Publish -> Unpublish -> Republish. Two unpublish
     buttons on one screen, one of them next to a delete, was a worse answer than
     one in the place the publish cycle already is.

     THE COUNT IS IN THE LABEL, and that is the safety mechanism. "Delete all
     shifts" says nothing about scope; "Delete all 14 shifts this week" cannot be
     misread. It names the store's visible span and nothing wider — see
     ShiftRangeService for why the range and never the table.

     A DANGER BUTTON, behind a confirm that spells out what goes: it withdraws
     from Humanity first, so it takes shifts off people's rosters. --}}
@php
    $total = (int) ($range['total'] ?? 0);
    $locked = (int) ($range['locked'] ?? 0);
    $live = (int) ($range['published_live'] ?? 0);

    // What the confirm has to say out loud: not "are you sure", but what
    // actually happens to Humanity and to the hours already worked.
    $deleteConfirm = 'Delete all '.$total.' shift'.($total === 1 ? '' : 's').' in '.$label.'?';

    if ($live > 0) {
        $deleteConfirm .= "\n\n".$live.' of them '.($live === 1 ? 'is' : 'are')
            .' live in Humanity and will be withdrawn there first, so nobody is left rostered for '
            .($live === 1 ? 'it' : 'them').'.';
    }

    $deleteConfirm .= "\n\nThis is a soft delete: worked hours keep pointing at the shifts, so the"
        ." pairing survives a restore from the database. Nothing on this screen undoes it.";
@endphp
<div class="card pad grow" style="border-left:4px solid {{ $total > 0 ? 'var(--line-2)' : 'var(--line)' }}">
  <div class="lbl">All of {{ $label }}</div>
  <div style="font-family:var(--mono);font-weight:700;font-size:13px;color:{{ $total > 0 ? 'var(--text-2)' : 'var(--text-3)' }}">
    {{ $total }} shift{{ $total === 1 ? '' : 's' }}
    @if ($locked > 0) · {{ $locked }} locked 🔒 @endif
  </div>
  <p class="note" style="margin:2px 0 8px">
    @if ($total === 0)
      Nothing rostered in {{ $label }}.
    @else
      Deleting withdraws {{ $live > 0 ? 'the '.$live.' live in Humanity' : 'anything live' }} from
      there first, then soft-deletes {{ $total === 1 ? 'the shift' : 'all '.$total }} here.
      @if ($locked > 0)
        {{ $locked }} {{ $locked === 1 ? 'is' : 'are' }} locked — use <strong>Unpublish</strong> on
        the Humanity card if you meant to edit rather than clear.
      @endif
    @endif
  </p>

  <div class="ctl" style="gap:6px">
    <form method="POST" action="{{ route('board.shifts.destroy-all') }}" class="inline js-range-delete">
      @csrf
      @method('DELETE')
      <input type="hidden" name="store_id" value="{{ $storeId }}">
      <input type="hidden" name="from" value="{{ $from }}">
      <input type="hidden" name="to" value="{{ $to }}">
      <button class="danger" @disabled($total === 0)
              data-confirm="{{ $deleteConfirm }}"
              title="Withdraw every shift in {{ $label }} from Humanity, then delete them here.">
        Delete all {{ $total > 0 ? $total : '' }}
      </button>
    </form>
  </div>
</div>

@once
  @push('scripts')
    <script>
    /*
     * The confirm on the range delete.
     *
     * A soft delete is only recoverable from the database, and this one reaches
     * every shift in the visible span — including published ones, which come off
     * people's rosters at the vendor on the way out. The dialog spells that out
     * rather than asking "are you sure": the count and the Humanity consequence
     * are the two facts worth reading before pressing it.
     *
     * On the SUBMIT rather than the click, so a keyboard Enter is caught too.
     */
    document.querySelectorAll('.js-range-delete').forEach(function (form) {
      form.addEventListener('submit', function (ev) {
        var button = form.querySelector('[data-confirm]');
        if (button && !window.confirm(button.dataset.confirm)) {
          ev.preventDefault();
        }
      });
    });
    </script>
  @endpush
@endonce
