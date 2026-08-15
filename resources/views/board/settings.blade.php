@extends('layouts.console')
@section('title', 'Settings — store '.$storeId)

@section('content')

{{-- ── which store ────────────────────────────────────────────────────────
     store_settings is SCHEDULING-OWNED and has no foreign key to stores, on
     purpose: constraining an owned row to a replayable projection means a
     rebuild either fails against the constraint or cascades away the settings
     it was supposed to preserve. --}}
<div class="card pad">
  <h1>Store {{ $stores->firstWhere('id', $storeId)?->store_number ?? $storeId }} · settings</h1>
  <form method="GET" action="{{ route('board.settings') }}" class="ctl" style="margin-top:10px">
    <label class="f"><span class="lbl">Store</span>
      <select name="store">
        @foreach ($stores as $s)
          <option value="{{ $s->id }}" @selected($s->id === $storeId)>{{ $s->store_number }}</option>
        @endforeach
      </select>
    </label>
    <button>Go</button>
    <a href="{{ route('board', ['store' => $storeId]) }}"><button type="button">back to the board</button></a>
  </form>

  @unless ($setting->exists)
    <p class="note" style="margin-top:9px">
      This store has <strong>no settings row yet</strong>. Everything below is the default
      it currently falls back to — saving creates the row.
    </p>
  @endunless
</div>

<div class="card pad">
  <form method="POST" action="{{ route('board.settings.update') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="store_id" value="{{ $storeId }}">

    <div class="lbl" style="margin-bottom:8px">Timezone</div>
    <label class="f" style="max-width:420px">
      <select name="timezone" style="width:100%">
        @foreach ($timezones as $tz)
          <option value="{{ $tz }}" @selected($tz === $setting->timezone)>{{ $tz }}</option>
        @endforeach
      </select>
    </label>
    <p class="note" style="margin:8px 0 0">
      <strong>Not a display preference.</strong> This is what turns a UTC <code>start_at</code>
      into a <code>business_date</code>, so it decides which calendar day every shift is filed
      under and which day an overnight shift belongs to.
    </p>
    <p class="note" style="margin:6px 0 0">
      Changing it does <strong>not</strong> rewrite history — <code>business_date</code> is stored,
      not recomputed on read — so shifts already saved keep the day they were filed under and only
      new writes use the new zone. Shifts near midnight either side of a change can therefore
      disagree. Restart any running queue workers afterwards: they cache the zone
      for the life of the process.
    </p>

    <div class="lbl" style="margin:16px 0 8px">Publishing</div>
    <div class="ctl">
      <label class="f"><span class="lbl">Lead days</span>
        <input type="number" name="publish_lead_days" min="0" max="365"
               value="{{ $setting->publish_lead_days ?? 14 }}">
      </label>
      <label class="f"><span class="lbl">Auto publish</span>
        <select name="auto_publish">
          <option value="0" @selected(! $setting->auto_publish)>off</option>
          <option value="1" @selected((bool) $setting->auto_publish)>on</option>
        </select>
      </label>
    </div>
    <p class="note" style="margin:8px 0 0">
      Lead days is how far ahead <code>schedule:publish</code> pushes when no <code>--to</code> is
      given. <strong>auto_publish is read by nothing today</strong> — it is stored and ignored, so
      turning it on schedules nothing. Nothing publishes to Humanity without somebody pressing the
      button.
    </p>

    <div style="margin-top:16px">
      <button class="primary">Save settings</button>
    </div>
  </form>
</div>

<div class="card pad">
  <p class="note" style="margin:0">
    <code>day_close_cutoff_time</code> is not on this form. The day-close workflow it belonged to
    was removed, and nothing reads the column — offering a control that changes no behaviour
    would be worse than leaving it out.
  </p>
</div>

@endsection
