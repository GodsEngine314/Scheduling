{{-- The only control on this console that talks to Humanity.
     $storeId, $from, $to, $publishable, $republishable, $range, $label

     ONE BUTTON, THREE STATES, and it cycles the way the workflow actually runs:

         prepare the week   ->  [Publish]     POST /shifts, the week goes live
         want to change it  ->  [Unpublish]   local only, nothing is sent
         changed it         ->  [Republish]   PUT over the same shifts

     After a publish there is nothing left to send, so the old card sat there
     with a DISABLED "Publish" button and the unlock lived in a different card
     entirely — which reads as a dead end at exactly the point where a manager
     wants to change something. The button in this slot is now always the next
     step, and its label says which step that is.

     WHY THE STATES ARE IN THIS ORDER. Sending wins whenever anything is
     outstanding, because an unsent change is the thing that matters: employees
     are reading a roster that does not match the plan. Only once everything in
     view is live AND unchanged does the button become the unlock. --}}
@php
    $locked = (int) ($range['locked'] ?? 0);
    $live = (int) ($range['published_live'] ?? 0);
    $republish = (int) ($republishable ?? 0);

    /*
     * SEND      something is outstanding. 'Republish' rather than 'Publish' when
     *           Humanity already holds any of it — "Publish" on a shift the store
     *           can already see reads like it is about to create a second one.
     * UNLOCK    everything in view is live and unchanged, so the only useful
     *           thing left is to open it for editing.
     * IDLE      nothing rostered, or nothing Humanity has ever seen.
     */
    $state = match (true) {
        $publishable > 0 => $republish > 0 ? 'republish' : 'publish',
        $locked > 0 => 'unlock',
        default => 'idle',
    };

    $accent = match ($state) {
        'publish', 'republish' => 'var(--planned)',
        'unlock' => 'var(--ok)',
        default => 'var(--line-2)',
    };
@endphp
<div class="card pad grow" style="border-left:4px solid {{ $accent }}">
  <div class="lbl">Humanity</div>

  @if ($state === 'unlock')
    <div style="font-family:var(--mono);font-weight:700;font-size:13px;color:var(--ok)">
      {{ $locked }} shift{{ $locked === 1 ? '' : 's' }} live
    </div>
    <p class="note" style="margin:2px 0 8px">
      Everything in {{ $label }} is published and unchanged. To edit, move or delete
      any of it, unpublish first — that is <strong>local only</strong>: Humanity is not
      contacted, and employees keep seeing {{ $locked === 1 ? 'this shift' : 'these shifts' }}
      exactly as {{ $locked === 1 ? 'it is' : 'they are' }} until you republish.
    </p>
  @elseif ($state === 'idle')
    <div style="font-family:var(--mono);font-weight:700;font-size:13px;color:var(--text-3)">
      Nothing to send
    </div>
    <p class="note" style="margin:2px 0 8px">
      @if (($range['total'] ?? 0) === 0)
        Nothing rostered in {{ $label }}. Build the schedule, then publish it.
      @else
        Nothing in {{ $label }} is waiting to go, and Humanity is not holding any of
        it either.
      @endif
    </p>
  @else
    <div style="font-family:var(--mono);font-weight:700;font-size:13px;color:var(--planned)">
      {{ $publishable }} shift{{ $publishable === 1 ? '' : 's' }} to send
    </div>
    <p class="note" style="margin:2px 0 8px">
      Drafts and edited shifts for {{ $label }}.
      @if ($republish > 0)
        {{ $republish }} of them
        {{ $republish === 1 ? 'is one Humanity' : 'are ones Humanity' }} already holds, so
        {{ $republish === 1 ? 'it goes' : 'they go' }} as
        <code>PUT /shifts/{id}</code> over the same shift — never a second
        <code>POST</code>, which would leave people holding two.
        @if ($publishable > $republish)
          The other {{ $publishable - $republish }} are new and go as <code>POST /shifts</code>.
        @endif
      @else
        All new, so they go as <code>POST /shifts</code>.
      @endif
      Live the instant it lands.
    </p>
  @endif

  @if ($state === 'unlock')
    {{-- Deliberately NOT the primary style. The primary button on this card is
         the one that talks to Humanity, and this one talks to nobody — dressing
         it the same would be the wrong promise. --}}
    <form method="POST" action="{{ route('board.shifts.unpublish-all') }}" class="inline">
      @csrf
      <input type="hidden" name="store_id" value="{{ $storeId }}">
      <input type="hidden" name="from" value="{{ $from }}">
      <input type="hidden" name="to" value="{{ $to }}">
      <button title="Unlock every published shift in {{ $label }} for editing. Humanity is not contacted.">
        Unpublish {{ $label }}
      </button>
    </form>
  @else
    <form method="POST" action="{{ route('board.publish') }}" class="inline">
      @csrf
      <input type="hidden" name="store_id" value="{{ $storeId }}">
      <input type="hidden" name="from" value="{{ $from }}">
      <input type="hidden" name="to" value="{{ $to }}">
      <button class="primary" @disabled($publishable === 0)>
        {{ $state === 'republish' ? 'Republish' : 'Publish' }} {{ $label }}
      </button>
    </form>

    {{-- THE MIXED STATE. Some of the week is outstanding and some of it is live
         and locked. The cycle above offers the send, which is the right next
         step, but the locked ones would then have no visible way to be opened at
         all — so the unlock stays reachable, quietly. --}}
    @if ($locked > 0)
      <form method="POST" action="{{ route('board.shifts.unpublish-all') }}" class="inline" style="margin-top:6px">
        @csrf
        <input type="hidden" name="store_id" value="{{ $storeId }}">
        <input type="hidden" name="from" value="{{ $from }}">
        <input type="hidden" name="to" value="{{ $to }}">
        <button class="mini" title="Unlock the {{ $locked }} still-locked shift(s) in {{ $label }} for editing. Humanity is not contacted.">
          also unpublish {{ $locked }} locked
        </button>
      </form>
    @endif
  @endif
</div>
