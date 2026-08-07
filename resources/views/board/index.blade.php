@extends('layouts.console')
@section('title', 'Board — store '.$storeId.' — '.$date)

@php
    use App\Support\BusinessDay;

    $bd = app(BusinessDay::class);

    // The lane spans 10:00 to 03:00 the next morning, in store-local minutes.
    $T0 = 600; $T1 = 1620;
    $pct = fn (int $m): float => (max($T0, min($T1, $m)) - $T0) / ($T1 - $T0) * 100;

    /** Local minutes-from-midnight for an instant, rolled past 1440 if it lands on the next day. */
    $mins = function ($instant) use ($bd, $storeId, $date): ?int {
        if ($instant === null) return null;
        $local = $bd->toLocal($storeId, $instant);
        $m = $local->hour * 60 + $local->minute;
        return $local->toDateString() > $date ? $m + 1440 : $m;
    };

    $hhmm = fn (?int $m): string => $m === null ? '—'
        : sprintf('%02d:%02d', intdiv($m % 1440, 60), $m % 60) . ($m >= 1440 ? '⁺' : '');

    $byEmployee = $shifts->groupBy('employee_id');
    $segsByEmployee = $segments->groupBy('employee_id');
@endphp

@section('content')

{{-- ── header ───────────────────────────────────────────────────────── --}}
<div class="row-flex">
  <div class="card pad grow">
    <h1>Store #{{ $storeId }} · {{ \Carbon\Carbon::parse($date)->format('l j M Y') }}</h1>
    <div class="lbl" style="margin-top:4px">{{ $timezone }} · business_date {{ $date }}</div>
    <form method="GET" action="{{ route('board') }}" class="ctl" style="margin-top:10px">
      <label class="f"><span class="lbl">Store</span>
        <select name="store">
          @foreach ($stores as $s)
            <option value="{{ $s->id }}" @selected($s->id === $storeId)>#{{ $s->id }} — {{ $s->store_number }}</option>
          @endforeach
        </select>
      </label>
      <label class="f"><span class="lbl">Date</span>
        <input type="date" name="date" value="{{ $date }}">
      </label>
      <button>Go</button>
      <a href="{{ route('board', ['store' => $storeId, 'date' => \Carbon\Carbon::parse($date)->subDay()->toDateString()]) }}"><button type="button">‹ prev</button></a>
      <a href="{{ route('board', ['store' => $storeId, 'date' => \Carbon\Carbon::parse($date)->addDay()->toDateString()]) }}"><button type="button">next ›</button></a>
    </form>
  </div>

  <div class="card pad stat">
    <div class="lbl">Planned cost</div>
    <div class="v">${{ number_format((float) ($board['cost']['planned_cost'] ?? 0), 2) }}</div>
    <div class="s">{{ number_format((float) ($board['cost']['planned_hours'] ?? 0), 2) }} planned h</div>
  </div>

  @php
      $workedHours = $segments->filter(fn ($g) => $g->time_out !== null)
          ->sum(fn ($g) => $g->time_in->diffInMinutes($g->time_out) / 60);
      $openCount = $segments->whereNull('time_out')->count();
  @endphp
  <div class="card pad stat">
    <div class="lbl">Actual hours</div>
    <div class="v">{{ number_format($workedHours, 2) }}h</div>
    <div class="s">{{ $openCount ? $openCount.' still clocked in' : 'all punched out' }}</div>
  </div>

  <div class="card pad grow" style="border-left:4px solid {{ $board['day_close']['closable'] ? 'var(--ok)' : 'var(--crit)' }}">
    <div class="lbl">Day close</div>
    @if ($board['day_close']['closable'])
      <div style="font-family:var(--mono);font-weight:700;color:var(--ok)">Ready to close</div>
      <p class="note" style="margin:2px 0 8px">Every segment approved, nobody still clocked in.</p>
    @else
      <div style="font-family:var(--mono);font-weight:700;color:var(--crit)">Blocked</div>
      <ul style="margin:4px 0 8px;padding-left:16px;font-size:11.5px;color:var(--text-2)">
        @foreach ($board['day_close']['blockers'] as $b)
          <li><b>{{ $b['type'] }}</b> — {{ $b['message'] }}</li>
        @endforeach
      </ul>
    @endif
    <form method="POST" action="{{ route('board.day-close') }}" class="inline">
      @csrf
      <input type="hidden" name="store_id" value="{{ $storeId }}">
      <input type="hidden" name="date" value="{{ $date }}">
      <button class="primary">Close the day</button>
    </form>
    <form method="POST" action="{{ route('board.segments.approve-all') }}" class="inline">
      @csrf
      <input type="hidden" name="store_id" value="{{ $storeId }}">
      <input type="hidden" name="date" value="{{ $date }}">
      <button>Approve all hours</button>
    </form>
  </div>
</div>

{{-- ── add a shift ───────────────────────────────────────────────────── --}}
<div class="card pad">
  <div class="lbl" style="margin-bottom:8px">Add a planned shift</div>
  <form method="POST" action="{{ route('board.shifts.store') }}" class="ctl">
    @csrf
    <input type="hidden" name="store_id" value="{{ $storeId }}">
    <input type="hidden" name="date" value="{{ $date }}">
    <label class="f"><span class="lbl">Employee</span>
      <select name="employee_id">
        <option value="">— open shift —</option>
        @foreach ($roster as $r)
          <option value="{{ $r['model']->id }}">{{ $r['model']->fullName() }}</option>
        @endforeach
      </select>
    </label>
    <label class="f"><span class="lbl">Position</span>
      <select name="position_id">
        @foreach ($positions as $p)<option value="{{ $p->id }}">{{ $p->label }}</option>@endforeach
      </select>
    </label>
    <label class="f"><span class="lbl">Start</span><input type="time" name="start" value="17:00" required></label>
    <label class="f"><span class="lbl">End</span><input type="time" name="end" value="21:00" required></label>
    <label class="f"><span class="lbl">Break min</span><input class="num" type="number" name="unpaid_break_minutes" value="0" min="0" max="240"></label>
    <button class="primary">Add shift</button>
  </form>
  {{-- A sibling, never nested: a form inside a form is dropped by the parser
       and its button silently submits the outer one instead. --}}
  <form method="POST" action="{{ route('board.reseed') }}" style="margin-top:10px">
    @csrf<button class="danger">Reset demo data</button>
  </form>
  <p class="note" style="margin-top:9px">
    An end at or before the start crosses midnight — try <code>21:00</code> to <code>01:00</code>.
    Availability is checked but never blocks the save.
  </p>
</div>

{{-- ── timeline ──────────────────────────────────────────────────────── --}}
<div class="card pad" style="overflow-x:auto">
  <div class="lbl" style="margin-bottom:8px">The day</div>
  <div class="tl">
    <div class="tl-head">
      <div class="tl-name"></div>
      <div class="tl-track">
        @for ($m = $T0; $m <= $T1; $m += 60)
          <span class="tick" style="left:{{ $pct($m) }}%">{{ $hhmm($m) }}</span>
        @endfor
      </div>
    </div>

    @foreach ($roster as $r)
      @php
          $e = $r['model'];
          $mine = $byEmployee->get($e->id, collect());
          $myPunches = $segsByEmployee->get($e->id, collect());
          $groups = $mine->whereNotNull('split_group_id')->groupBy('split_group_id');
      @endphp
      <div class="lane-row">
        <div class="who">
          <div class="n">{{ $e->fullName() }}</div>
          <div class="d">
            <span>{{ $r['rate'] !== null ? '$'.number_format($r['rate'], 2).'/h' : 'no rate' }}</span>
            @if ($r['age'] !== null && $r['age'] < 18)<span class="chip warn">minor {{ $r['age'] }}</span>@endif
            @if (\App\Models\EmployeeRequest::approvedTimeOffCovering($e->id, $date)->exists())
              <span class="chip crit">on leave</span>
            @endif
          </div>
        </div>
        <div class="lane">
          <div class="grid">
            @for ($m = $T0 + 60; $m < $T1; $m += 60)<i style="left:{{ $pct($m) }}%"></i>@endfor
          </div>

          @foreach ($r['windows'] as $w)
            @php
                $wf = (int) substr($w->available_from, 0, 2) * 60 + (int) substr($w->available_from, 3, 2);
                $wt = (int) substr($w->available_to, 0, 2) * 60 + (int) substr($w->available_to, 3, 2);
                if ($wt <= $wf) { $wt += 1440; }   // wraps past midnight
            @endphp
            <div class="avail" style="left:{{ $pct($wf) }}%;width:{{ max($pct($wt) - $pct($wf), 0) }}%"></div>
          @endforeach

          @foreach ($groups as $gid => $parts)
            @php
                $sorted = $parts->sortBy('start_at')->values();
                $a = $mins($sorted->first()->end_at);
                $b = $mins($sorted->last()->start_at);
            @endphp
            @if ($a !== null && $b !== null && $b > $a)
              <div class="splitlink" style="left:{{ $pct($a) }}%;width:{{ max($pct($b) - $pct($a), 0) }}%"></div>
            @endif
          @endforeach

          @foreach ($mine as $s)
            @php $ms = $mins($s->start_at); $me = $mins($s->end_at); @endphp
            <div class="bar plan {{ $s->availability_check?->value === 'outside_availability' ? 'bad' : '' }}"
                 title="shift #{{ $s->id }} — {{ $hhmm($ms) }}→{{ $hhmm($me) }} — {{ $s->availability_check?->value }}"
                 style="left:{{ $pct($ms) }}%;width:{{ max($pct($me) - $pct($ms), 2) }}%">
              #{{ $s->id }}@if ($s->split_part) p{{ $s->split_part }}@endif {{ $hhmm($ms) }}
            </div>
          @endforeach

          @foreach ($myPunches as $g)
            @php $gi = $mins($g->time_in); $go = $g->time_out ? $mins($g->time_out) : $T1; @endphp
            <div class="bar seg {{ $g->shift_id === null ? 'unmatched' : '' }} {{ $g->time_out === null ? 'openpunch' : '' }}"
                 title="segment #{{ $g->id }} — {{ $g->time_out === null ? 'still clocked in' : $hhmm($gi).'→'.$hhmm($go) }}"
                 style="left:{{ $pct($gi) }}%;width:{{ max($pct($go) - $pct($gi), 2) }}%">
              {{ $g->time_out === null ? 'open punch' : $hhmm($gi).'→'.$hhmm($go) }}
            </div>
          @endforeach
        </div>
      </div>
    @endforeach

    @php $open = $shifts->whereNull('employee_id'); @endphp
    @if ($open->isNotEmpty())
      <div class="lane-row">
        <div class="who"><div class="n" style="color:var(--text-3)">— open shifts —</div>
          <div class="d">employee_id IS NULL</div></div>
        <div class="lane">
          <div class="grid">@for ($m = $T0 + 60; $m < $T1; $m += 60)<i style="left:{{ $pct($m) }}%"></i>@endfor</div>
          @foreach ($open as $s)
            @php $ms = $mins($s->start_at); $me = $mins($s->end_at); @endphp
            <div class="bar plan open" style="left:{{ $pct($ms) }}%;width:{{ max($pct($me) - $pct($ms), 2) }}%">
              #{{ $s->id }} unfilled
            </div>
          @endforeach
        </div>
      </div>
    @endif
  </div>

  <div class="legend">
    <span><i class="key" style="background:var(--surface);border:1px solid var(--line-2)"></i>availability window</span>
    <span><i class="key" style="background:var(--planned-soft);border:1px solid var(--planned)"></i>planned</span>
    <span><i class="key" style="border:1px dashed var(--crit)"></i>outside availability</span>
    <span><i class="key" style="background:var(--actual-soft);border:1px solid var(--actual)"></i>worked</span>
    <span><i class="key" style="border:1px dotted var(--actual)"></i>no matching shift</span>
    <span>··· split parts</span>
  </div>
</div>

{{-- ── plan vs reality ───────────────────────────────────────────────── --}}
<div class="row-flex">
  <div class="card pad grow">
    <div class="lbl">Scheduled, nobody turned up</div>
    @forelse ($board['scheduled_absent'] as $a)
      <div style="font-family:var(--mono);font-size:11.5px;margin-top:4px">
        {{ $a['employee_name'] }} <span class="chip warn">shift {{ implode(', #', $a['shift_ids']) }}</span>
      </div>
    @empty
      <p class="note" style="margin-top:4px">Nobody.</p>
    @endforelse
  </div>
  <div class="card pad grow">
    <div class="lbl">Turned up, nothing planned</div>
    @forelse ($board['present_unscheduled'] as $p)
      <div style="font-family:var(--mono);font-size:11.5px;margin-top:4px">
        {{ $p['employee_name'] }}
        <span class="chip {{ $p['unmatched'] ? 'crit' : 'neutral' }}">{{ $p['unmatched'] ? 'unmatched' : 'matched elsewhere' }}</span>
      </div>
    @empty
      <p class="note" style="margin-top:4px">Nobody.</p>
    @endforelse
  </div>
</div>

{{-- ── shifts table ──────────────────────────────────────────────────── --}}
<div class="card pad">
  <div class="tbl-wrap">
    <table>
      <caption>shifts <em style="font-style:normal;color:var(--text-3);text-transform:none;letter-spacing:0">planned · scheduling-owned</em></caption>
      <thead><tr>
        <th>id</th><th>employee</th><th>local</th><th>break</th><th>paid h</th><th>cost</th>
        <th>availability</th><th>split</th><th>publish</th><th>warnings</th><th>actions</th>
      </tr></thead>
      <tbody>
      @forelse ($shifts as $s)
        @php
            $ms = $mins($s->start_at); $me = $mins($s->end_at);
            $chk = $s->availability_check?->value;
            $rate = collect($roster)->firstWhere('model.id', $s->employee_id)['rate'] ?? null;
        @endphp
        <tr>
          <td class="k">#{{ $s->id }}</td>
          <td>{{ $s->employee?->fullName() ?? '—' }}</td>
          <td>{{ $hhmm($ms) }}→{{ $hhmm($me) }}</td>
          <td>{{ $s->unpaid_break_minutes }}m</td>
          <td>{{ number_format($s->paidHours(), 2) }}</td>
          <td>{{ $s->employee_id && $rate ? '$'.number_format($s->paidHours() * $rate, 2) : '—' }}</td>
          <td><span class="chip {{ $chk === 'ok' ? 'ok' : ($chk === 'unknown' ? 'neutral' : 'crit') }}">{{ $chk }}</span></td>
          <td>{{ $s->split_part ? 'p'.$s->split_part.' · '.substr((string) $s->split_group_id, -5) : '—' }}</td>
          <td><span class="chip plan">{{ $s->publish_state?->value }}</span></td>
          <td>
            @forelse ($conflicts[$s->id] ?? [] as $c)
              <div><span class="chip warn">{{ $c['type'] }}</span> {{ $c['message'] ?? '' }}</div>
            @empty — @endforelse
          </td>
          <td>
            <button class="mini" type="button" onclick="document.getElementById('edit-shift-{{ $s->id }}').hidden = !document.getElementById('edit-shift-{{ $s->id }}').hidden">edit</button>
            <button class="mini" type="button"
                    data-split-url="{{ route('board.shifts.split', $s) }}"
                    data-shift="{{ $s->id }}"
                    data-who="{{ $s->employee?->fullName() ?? 'Open shift' }}"
                    data-part1="{{ $hhmm($mins($s->start_at)) }}–{{ $hhmm($mins($s->end_at)) }}"
                    data-end-hhmm="{{ str_replace('⁺', '', $hhmm($mins($s->end_at))) }}"
                    data-end-day="{{ $bd->toLocal($storeId, $s->end_at)->toDateString() }}"
                    data-business-date="{{ $s->business_date instanceof \Carbon\CarbonInterface ? $s->business_date->toDateString() : $s->business_date }}"
                    onclick="openSplit(this)">split</button>
            <form method="POST" action="{{ route('board.shifts.punch-in', $s) }}" class="inline">@csrf<button class="mini">punch in</button></form>
            <form method="POST" action="{{ route('board.shifts.destroy', $s) }}" class="inline">
              @csrf @method('DELETE')<button class="mini danger">del</button>
            </form>
          </td>
        </tr>
        <tr id="edit-shift-{{ $s->id }}" hidden>
          <td colspan="11" style="background:var(--surface-2)">
            <form method="POST" action="{{ route('board.shifts.update', $s) }}" class="ctl" style="gap:8px">
              @csrf @method('PUT')
              <input type="hidden" name="date" value="{{ $date }}">
              <label class="f"><span class="lbl">Employee</span>
                <select name="employee_id">
                  <option value="">— open shift —</option>
                  @foreach ($roster as $r)
                    <option value="{{ $r['model']->id }}" @selected($r['model']->id === $s->employee_id)>{{ $r['model']->fullName() }}</option>
                  @endforeach
                </select>
              </label>
              <label class="f"><span class="lbl">Position</span>
                <select name="position_id">
                  <option value="">—</option>
                  @foreach ($positions as $p)
                    <option value="{{ $p->id }}" @selected($p->id === $s->position_id)>{{ $p->label }}</option>
                  @endforeach
                </select>
              </label>
              <label class="f"><span class="lbl">Start</span>
                <input type="time" name="start" value="{{ str_replace('⁺', '', $hhmm($mins($s->start_at))) }}" required></label>
              <label class="f"><span class="lbl">End</span>
                <input type="time" name="end" value="{{ str_replace('⁺', '', $hhmm($mins($s->end_at))) }}" required></label>
              <label class="f"><span class="lbl">Break min</span>
                <input class="num" type="number" name="unpaid_break_minutes" value="{{ $s->unpaid_break_minutes }}" min="0" max="240"></label>
              <label class="f" style="flex:1 1 200px"><span class="lbl">Notes</span>
                <input type="text" name="notes" value="{{ $s->notes }}" style="width:100%"></label>
              <button class="primary">Save shift</button>
              <span class="note" style="align-self:center">
                @if ($s->publish_state?->value === 'published')
                  Already in Humanity — saving only queues the change for the next publish run.
                @else
                  Draft. Saving sends nothing to Humanity.
                @endif
              </span>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="11" class="empty">No shifts on this date.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

{{-- ── segments table ────────────────────────────────────────────────── --}}
<div class="card pad">
  <div class="tbl-wrap">
    <table>
      <caption>work_segments <em style="font-style:normal;color:var(--text-3);text-transform:none;letter-spacing:0">actual · scheduling-owned</em></caption>
      <thead><tr>
        <th>id</th><th>employee</th><th>shift_id</th><th>match_source</th><th>time_in</th><th>time_out</th>
        <th>hours</th><th>approved</th><th>origin</th><th>tcp</th><th>actions</th>
      </tr></thead>
      <tbody>
      @forelse ($segments as $g)
        @php $gi = $mins($g->time_in); $go = $g->time_out ? $mins($g->time_out) : null; @endphp
        <tr>
          <td class="k">#{{ $g->id }}</td>
          <td>{{ $g->employee?->fullName() }}</td>
          @if ($g->shift_id) <td>#{{ $g->shift_id }}</td> @else <td class="null">NULL</td> @endif
          <td><span class="chip {{ $g->match_source?->value === 'unmatched' ? 'warn' : 'neutral' }}">{{ $g->match_source?->value }}</span></td>
          <td>{{ $hhmm($gi) }}</td>
          @if ($go === null) <td class="null">NULL — open punch</td> @else <td>{{ $hhmm($go) }}</td> @endif
          <td>{{ $g->hours !== null ? number_format((float) $g->hours, 2) : '—' }}</td>
          <td><span class="chip {{ $g->manager_approval ? 'ok' : 'crit' }}">{{ $g->manager_approval ? 'true' : 'false' }}</span></td>
          <td>{{ $g->origin?->value }}</td>
          <td>
            @php $tcp = $g->tcp_sync_state; @endphp
            <span class="chip {{ $tcp?->value === 'synced' ? 'ok' : ($tcp?->value === 'failed' ? 'crit' : ($tcp?->value === 'pending' ? 'warn' : 'neutral')) }}"
                  title="{{ $g->tcp_sync_error ?: ($g->tcp_segment_id ? 'TCP id '.$g->tcp_segment_id : 'no TCP id yet') }}">{{ $tcp?->label() }}</span>
          </td>
          <td>
            <button class="mini" type="button" onclick="document.getElementById('edit-seg-{{ $g->id }}').hidden = !document.getElementById('edit-seg-{{ $g->id }}').hidden">edit</button>
            @if ($g->time_out === null)
              <form method="POST" action="{{ route('board.segments.punch-out', $g) }}" class="inline">@csrf<button class="mini">punch out</button></form>
            @elseif (! $g->manager_approval)
              <form method="POST" action="{{ route('board.segments.approve', $g) }}" class="inline">@csrf<button class="mini">approve</button></form>
            @endif
            <form method="POST" action="{{ route('board.segments.destroy', $g) }}" class="inline">
              @csrf @method('DELETE')<button class="mini danger">del</button>
            </form>
          </td>
        </tr>
        <tr id="edit-seg-{{ $g->id }}" hidden>
          <td colspan="11" style="background:var(--surface-2)">
            <form method="POST" action="{{ route('board.segments.update', $g) }}" class="ctl" style="gap:8px">
              @csrf @method('PUT')
              <input type="hidden" name="date" value="{{ $date }}">
              <label class="f"><span class="lbl">Clock in</span>
                <input type="time" name="time_in" value="{{ str_replace('⁺', '', $hhmm($gi)) }}" required></label>
              <label class="f"><span class="lbl">Clock out</span>
                <input type="time" name="time_out" value="{{ $go === null ? '' : str_replace('⁺', '', $hhmm($go)) }}"></label>
              <label class="f"><span class="lbl">Re-approve</span>
                <select name="reapprove">
                  <option value="0">no — clear approval</option>
                  <option value="1">yes — keep approved</option>
                </select>
              </label>
              <button class="primary">Save &amp; push to TCP</button>
              <span class="note" style="align-self:center">
                PUT /worksegments/{{ $g->tcp_segment_id ?: '…' }} — queued, retried on 429/5xx.
                A correction clears the approval unless you say otherwise.
              </span>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="11" class="empty">No punches on this date.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

{{-- ── requests ──────────────────────────────────────────────────────── --}}
<div class="card pad">
  <div class="tbl-wrap">
    <table>
      <caption>employee_requests <em style="font-style:normal;color:var(--text-3);text-transform:none;letter-spacing:0">+ decision trail</em></caption>
      <thead><tr>
        <th>id</th><th>employee</th><th>type</th><th>dates</th><th>status</th><th>decisions</th><th>description</th><th></th>
      </tr></thead>
      <tbody>
      @forelse ($requests as $q)
        <tr>
          <td class="k">#{{ $q->id }}</td>
          <td>{{ $q->employee?->fullName() }}</td>
          <td>{{ $q->request_type?->value }}</td>
          <td>{{ $q->start_date?->toDateString() ?? '—' }} → {{ $q->end_date?->toDateString() ?? '—' }}</td>
          <td><span class="chip {{ $q->status?->value === 'approved' ? 'ok' : ($q->status?->value === 'pending' ? 'neutral' : 'warn') }}">{{ $q->status?->value }}</span></td>
          <td>
            @forelse ($q->decisions->sortBy('id') as $d)
              <div>{{ $d->decision?->value }} <span style="color:var(--text-3)">{{ $d->completed_at?->format('H:i') }}</span></div>
            @empty — @endforelse
          </td>
          <td style="white-space:normal;max-width:280px">{{ $q->description }}</td>
          <td>
            @foreach (['approved', 'denied', 'cancelled'] as $d)
              <form method="POST" action="{{ route('board.requests.decide', $q) }}" class="inline">
                @csrf<input type="hidden" name="decision" value="{{ $d }}">
                <button class="mini">{{ $d }}</button>
              </form>
            @endforeach
          </td>
        </tr>
      @empty
        <tr><td colspan="8" class="empty">No requests for this store.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

{{-- ── split dialog ──────────────────────────────────────────────────
     One dialog for every row, repopulated from the button that opened it.
     Five rows would otherwise mean five copies of the same markup, and five
     places for them to drift apart. Native <dialog>: Esc closes it, focus is
     trapped, and it needs no library. --}}
<dialog id="split-dialog" class="card" style="padding:0;border:1px solid var(--line-2);max-width:520px">
  <form method="POST" id="split-form" style="margin:0;padding:16px;display:flex;flex-direction:column;gap:12px">
    @csrf
    <input type="hidden" name="date" id="split-date" value="{{ $date }}">

    <div>
      <div class="lbl">Split a planned shift</div>
      <div style="font-family:var(--mono);font-size:13px;font-weight:700;margin-top:3px" id="split-title">—</div>
      <div class="note" style="margin-top:2px" id="split-part1">—</div>
    </div>

    <div class="ctl" style="gap:10px">
      <label class="f"><span class="lbl">Part 2 starts</span>
        <input type="time" name="second_start" id="split-start" required></label>
      <label class="f"><span class="lbl">Part 2 ends</span>
        <input type="time" name="second_end" id="split-end" required></label>
    </div>

    <div id="split-hint" class="note" style="border-left:3px solid var(--line-2);padding-left:9px">—</div>

    <p class="note" style="margin:0">
      Two rows, never one row with a hole in it. The gap between the parts is
      <strong>unpaid and is not a break</strong> — part 2 starts with no break of its own,
      and each part is checked against availability separately.
    </p>

    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button type="button" onclick="document.getElementById('split-dialog').close()">Cancel</button>
      <button class="primary" type="submit">Create part 2</button>
    </div>
  </form>
</dialog>

<script>
  const splitDialog = document.getElementById('split-dialog');
  const splitForm   = document.getElementById('split-form');
  const splitStart  = document.getElementById('split-start');
  const splitEnd    = document.getElementById('split-end');
  const splitHint   = document.getElementById('split-hint');
  const boardDate   = @json($date);

  const toMin = v => { const [h, m] = v.split(':').map(Number); return h * 60 + m; };
  const fmt   = m => String(Math.floor((m % 1440) / 60)).padStart(2, '0') + ':' +
                     String(m % 60).padStart(2, '0');

  let part1EndMin = 0, part1EndDay = boardDate, part1BusinessDate = boardDate;

  const addDay = d => {
    const t = new Date(d + 'T00:00:00Z');
    t.setUTCDate(t.getUTCDate() + 1);
    return t.toISOString().slice(0, 10);
  };

  function openSplit(btn) {
    splitForm.action = btn.dataset.splitUrl;
    document.getElementById('split-title').textContent =
      btn.dataset.who + ' · shift #' + btn.dataset.shift;
    document.getElementById('split-part1').textContent = 'Part 1 runs ' + btn.dataset.part1 + '.';

    part1EndMin = toMin(btn.dataset.endHhmm);
    part1EndDay = btn.dataset.endDay;
    part1BusinessDate = btn.dataset.businessDate;
    document.getElementById('split-date').value = part1EndDay;

    // Default to a three-hour gap, then a three-hour block.
    splitStart.value = fmt(part1EndMin + 180);
    splitEnd.value   = fmt(part1EndMin + 360);

    updateSplitHint();
    splitDialog.showModal();
  }

  function updateSplitHint() {
    if (!splitStart.value || !splitEnd.value) { splitHint.textContent = '—'; return; }

    let start = toMin(splitStart.value);
    let end   = toMin(splitEnd.value);

    // Mirror the server: both ends roll forward together when the block wraps
    // midnight, then the start rolls again if it lands before part 1 ends.
    if (end <= start) end += 1440;
    if (start < part1EndMin) { start += 1440; end += 1440; }

    const gap    = start - part1EndMin;
    const length = end - start;

    // business_date is the calendar day the BLOCK starts on, so part 2's day is
    // part 1's end day, plus one if the start wrapped past midnight. Compare
    // that with part 1's own business_date — not with its end day, which is
    // already the next date whenever part 1 itself crosses midnight.
    const part2Day = start >= 1440 ? addDay(part1EndDay) : part1EndDay;
    const strays = part2Day !== part1BusinessDate;

    splitHint.innerHTML =
      '<strong>' + Math.floor(gap / 60) + 'h ' + (gap % 60) + 'm unpaid gap</strong>, then ' +
      (length / 60).toFixed(2) + ' paid hours.' +
      (strays
        ? ' <span style="color:var(--warn)">Part 2 lands on ' + part2Day + ', a different business date' +
          ' from part 1 — it will not appear on this board. Open that date to see it.</span>'
        : '');
  }

  splitStart.addEventListener('input', updateSplitHint);
  splitEnd.addEventListener('input', updateSplitHint);
</script>

<p class="note">
  Every button on this page calls the same service the JSON API calls — <code>ShiftService</code>,
  <code>WorkSegmentService</code>, <code>DayCloseService</code>, <code>EmployeeRequestService</code>.
  A domain refusal (approving an open punch, ending a shift before it starts) comes back as the
  red banner, not a stack trace.
</p>

@endsection
