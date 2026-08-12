{{-- The only control on this console that talks to Humanity.
     $storeId, $from, $to, $publishable, $label --}}
<div class="card pad grow" style="border-left:4px solid {{ $publishable > 0 ? 'var(--planned)' : 'var(--line-2)' }}">
  <div class="lbl">Humanity</div>

  @if ($publishable > 0)
    <div style="font-family:var(--mono);font-weight:700;font-size:13px;color:var(--planned)">
      {{ $publishable }} shift{{ $publishable === 1 ? '' : 's' }} to send
    </div>
    <p class="note" style="margin:2px 0 8px">
      Drafts and edited shifts for {{ $label }}. New shifts go as
      <code>POST /shifts</code>; a shift Humanity already holds goes as
      <code>PUT /shifts/{id}</code>. Live the instant it lands.
    </p>
  @else
    <div style="font-family:var(--mono);font-weight:700;font-size:13px;color:var(--text-3)">
      Nothing to send
    </div>
    <p class="note" style="margin:2px 0 8px">
      Everything in {{ $label }} is already live and unchanged. Pressing publish
      costs no request — a matching fingerprint is reported as unchanged.
    </p>
  @endif

  <form method="POST" action="{{ route('board.publish') }}" class="inline">
    @csrf
    <input type="hidden" name="store_id" value="{{ $storeId }}">
    <input type="hidden" name="from" value="{{ $from }}">
    <input type="hidden" name="to" value="{{ $to }}">
    <button class="primary" @disabled($publishable === 0)>Publish {{ $label }}</button>
  </form>
</div>
