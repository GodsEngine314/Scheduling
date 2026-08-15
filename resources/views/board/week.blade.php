@extends('layouts.console')
@section('title', ($view === 'actual' ? 'Actual' : 'Planned').' week — store '.$storeId.' — '.$weekStart)

@php
    use App\Support\BusinessDay;

    $bd = app(BusinessDay::class);
    $hhmm = fn ($instant): string => $instant === null ? '—'
        : $bd->toLocal($storeId, $instant)->format('H:i');

    $prevWeek = \Carbon\CarbonImmutable::parse($weekStart)->subWeek()->toDateString();
    $nextWeek = \Carbon\CarbonImmutable::parse($weekStart)->addWeek()->toDateString();

    $actual = $view === 'actual';

    // Cells are addressed by "employee id or the string open" + date, which is
    // exactly what the drop handler posts back.
    $cell = fn ($employeeId, string $day) => data_get($byCell, [(string) $employeeId, $day], collect());
    // The actual side has no open row: a punch is somebody clocking in, so
    // there is always a person behind it.
    $segCell = fn ($employeeId, string $day) => data_get($segsByCell, [(string) $employeeId, $day], collect());

    $tab = fn (string $v): string => route('board.week', [
        'store' => $storeId, 'week' => $weekStart, 'view' => $v,
    ]);
    $nav = fn (string $week): string => route('board.week', [
        'store' => $storeId, 'week' => $week, 'view' => $view,
    ]);
@endphp

@section('content')

{{-- ── which week am I reading ───────────────────────────────────────────
     Two readings of the same seven days. They are separate views rather than
     two chips in one cell because they answer different questions — "who is
     working Thursday" and "did Thursday get worked" — and because one cell
     holding both is unreadable the moment anybody works a split shift. --}}
<div class="card pad">
  <nav class="topbar-nav" style="margin-bottom:8px">
    <a href="{{ $tab('planned') }}" class="{{ $actual ? '' : 'on' }}">Planned shifts</a>
    <a href="{{ $tab('actual') }}" class="{{ $actual ? 'on' : '' }}">
      Actual hours
      @if ($actuals['unapproved'] > 0)
        <span class="chip crit" style="margin-left:5px">{{ $actuals['unapproved'] }} to approve</span>
      @endif
      @if ($actuals['open_punches'] > 0)
        <span class="chip warn" style="margin-left:5px">{{ $actuals['open_punches'] }} still in</span>
      @endif
    </a>
  </nav>
  <p class="note" style="margin:0">
    @if ($actual)
      <strong>What was worked.</strong> Punches pulled from TCP with <code>GET /worksegments</code>.
      A punch with no clock-out is somebody <strong>still in the store</strong> — it shows its clock-in
      alone and cannot be approved, because there are no hours yet. Approve, correct or delete
      each one here; every write is queued back to TCP.
    @else
      <strong>What we intend.</strong> Drag to build it, then publish the week to Humanity.
      Nothing on this tab is sent to TCP, and nothing on the actual tab is sent to Humanity.
    @endif
  </p>
</div>

<div class="row-flex">
  <div class="card pad grow">
    <h1>Store {{ $stores->firstWhere('id', $storeId)?->store_number ?? $storeId }} · week of {{ \Carbon\Carbon::parse($weekStart)->format('j M Y') }}</h1>
    <div class="lbl" style="margin-top:4px">{{ $timezone }}</div>
    <form method="GET" action="{{ route('board.week') }}" class="ctl" style="margin-top:10px">
      {{-- Carried through the store/week form, or picking a store would drop
           you back on the planned tab you did not ask for. --}}
      <input type="hidden" name="view" value="{{ $view }}">
      <label class="f"><span class="lbl">Store</span>
        {{-- See board/index: labelled by store_number, not id. --}}
        <select name="store">
          @foreach ($stores as $s)
            <option value="{{ $s->id }}" @selected($s->id === $storeId)>{{ $s->store_number }}</option>
          @endforeach
        </select>
      </label>
      <label class="f"><span class="lbl">Week of</span><input type="date" name="week" value="{{ $weekStart }}"></label>
      <button>Go</button>
      <a href="{{ $nav($prevWeek) }}"><button type="button">‹ prev</button></a>
      <a href="{{ $nav($nextWeek) }}"><button type="button">next ›</button></a>
    </form>
  </div>

  @if ($actual)
    <div class="card pad stat">
      <div class="lbl">Actual cost</div>
      <div class="v">${{ number_format((float) $actuals['actual_cost'], 2) }}</div>
      <div class="s">{{ number_format((float) $actuals['actual_hours'], 2) }} h worked</div>
    </div>

    {{-- The other direction. Planned shifts go OUT to Humanity; worked hours
         come IN from TCP, and neither crosses over. --}}
    <div class="card pad grow" style="border-left:4px solid var(--actual)">
      <div class="lbl">TCP</div>
      <div style="font-family:var(--mono);font-weight:700;font-size:13px;color:var(--actual)">
        {{ $segments->count() }} punch{{ $segments->count() === 1 ? '' : 'es' }} this week
      </div>
      <p class="note" style="margin:2px 0 8px">
        One request for the whole week. Re-pulling is free: the upsert is keyed on
        <code>tcp_segment_id</code>, and a row somebody has approved or corrected is held
        rather than overwritten.
      </p>
      <form method="POST" action="{{ route('board.pull-segments') }}" class="inline">
        @csrf
        <input type="hidden" name="store_id" value="{{ $storeId }}">
        <input type="hidden" name="date" value="{{ $days[0] }}">
        <input type="hidden" name="to" value="{{ $days[6] }}">
        <button class="primary">Pull the week's actual hours</button>
      </form>
    </div>

    <div class="card pad stat" style="border-left:4px solid {{ $actuals['unapproved'] > 0 || $actuals['open_punches'] > 0 ? 'var(--warn)' : 'var(--line-2)' }}">
      <div class="lbl">Outstanding</div>
      <div class="v">{{ $actuals['unapproved'] }}</div>
      <div class="s">
        to approve
        @if ($actuals['open_punches'] > 0) · {{ $actuals['open_punches'] }} still in @endif
      </div>
    </div>
  @else
    <div class="card pad stat">
      <div class="lbl">Planned cost</div>
      <div class="v">${{ number_format((float) ($costs['planned_cost'] ?? 0), 2) }}</div>
      <div class="s">{{ number_format((float) ($costs['planned_hours'] ?? 0), 2) }} h this week</div>
    </div>

    @include('board._publish', [
        'storeId' => $storeId, 'from' => $days[0], 'to' => $days[6],
        'publishable' => $publishable, 'label' => 'this week',
    ])

    <div class="card pad stat">
      <div class="lbl">Shifts</div>
      <div class="v">{{ $shifts->count() }}</div>
      <div class="s">{{ $shifts->whereNull('employee_id')->count() }} unfilled</div>
    </div>
  @endif
</div>

@if ($actual)
  {{-- ── record hours nobody punched ──────────────────────────────────────
       The document's "forgot to clock in" workflow. Leaving the clock-out empty
       is not an incomplete form: it records somebody who is still in the store,
       which is exactly what an open punch is. --}}
  <div class="card pad">
    <div class="lbl" style="margin-bottom:8px">Record actual hours by hand</div>
    <form method="POST" action="{{ route('board.segments.store') }}" class="ctl">
      @csrf
      <input type="hidden" name="store_id" value="{{ $storeId }}">
      <label class="f"><span class="lbl">Day</span>
        <select name="date">
          @foreach ($days as $d)
            <option value="{{ $d }}">{{ \Carbon\Carbon::parse($d)->format('D j M') }}</option>
          @endforeach
        </select>
      </label>
      <label class="f"><span class="lbl">Employee</span>
        <select name="employee_id" required>
          @foreach ($roster as $r)
            <option value="{{ $r['model']->id }}">{{ $r['model']->fullName() }}</option>
          @endforeach
        </select>
      </label>
      <label class="f"><span class="lbl">Position</span>
        <select name="position_id">
          <option value="">— none —</option>
          @foreach ($positions as $p)<option value="{{ $p->id }}">{{ $p->label }}</option>@endforeach
        </select>
      </label>
      <label class="f"><span class="lbl">Clocked in</span><input type="time" name="time_in" value="17:00" required></label>
      <label class="f"><span class="lbl">Clocked out</span><input type="time" name="time_out"></label>
      <label class="f"><span class="lbl">Break min</span><input type="number" class="num" name="break_minutes" value="0" min="0" max="1440"></label>
      <button class="primary">Record hours</button>
    </form>
    <p class="note" style="margin-top:9px">
      Leave <strong>clocked out</strong> empty for somebody who is still in the store.
      It is created here first and pushed to TCP by a queued job, so a TCP outage
      leaves visible hours rather than losing them — and it arrives unapproved.
    </p>
  </div>
@else
  {{-- ── add a shift ────────────────────────────────────────────────────── --}}
  <div class="card pad">
    <div class="lbl" style="margin-bottom:8px">Add a planned shift</div>
    <form method="POST" action="{{ route('board.shifts.store') }}" class="ctl">
      @csrf
      <input type="hidden" name="store_id" value="{{ $storeId }}">
      <label class="f"><span class="lbl">Day</span>
        <select name="date">
          @foreach ($days as $d)
            <option value="{{ $d }}">{{ \Carbon\Carbon::parse($d)->format('D j M') }}</option>
          @endforeach
        </select>
      </label>
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
      <button class="primary">Add shift</button>
    </form>
    <p class="note" style="margin-top:9px">
      No break field: break time is TCP's, and it arrives on the punch from
      <code>GET /worksegments</code>. An end at or before the start crosses midnight.
    </p>
  </div>
@endif

{{-- ── the grid ───────────────────────────────────────────────────────── --}}
<div class="card pad" style="overflow-x:auto">
  <div class="lbl" style="margin-bottom:8px">The week · {{ $actual ? 'actual hours' : 'planned shifts' }}</div>

  <table class="week">
    <thead>
      <tr>
        <th class="wk-name">Employee</th>
        @foreach ($days as $d)
          <th>{{ \Carbon\Carbon::parse($d)->format('D') }}
            <span style="color:var(--text-3);font-weight:400">{{ \Carbon\Carbon::parse($d)->format('j M') }}</span>
          </th>
        @endforeach
        <th class="wk-total">Week</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($rows as $r)
        @php
            $e = $r['model'];
            $total = $actuals['per_employee'][$e->id] ?? null;
            $plannedRow = collect($costs['per_employee'] ?? [])->firstWhere('employee_id', $e->id);
        @endphp
        <tr>
          <td class="wk-name">
            <div class="n">{{ $e->fullName() }}</div>
            <div class="d">
              {{ $r['rate'] !== null ? '$'.number_format($r['rate'], 2).'/h' : 'no rate' }}
              @if ($r['off_roster'] ?? false)
                {{-- Worked here, cannot be scheduled here: terminated since, or
                     covering from another store. The hours are real either way. --}}
                <span class="chip warn" title="Punched at this store but not on its current roster — terminated since, or covering from another store">off roster</span>
              @endif
            </div>
          </td>
          @foreach ($days as $d)
            <td class="wk-cell {{ $actual ? 'actual' : '' }}" data-employee="{{ $e->id }}" data-date="{{ $d }}">
              @if ($actual)
                @foreach ($segCell($e->id, $d) as $g)
                  @include('board._segment-chip', ['g' => $g])
                @endforeach
              @else
                @foreach ($cell($e->id, $d) as $s)
                  @include('board._shift-chip', ['s' => $s, 'hhmm' => $hhmm])
                @endforeach
              @endif
            </td>
          @endforeach
          <td class="wk-total">
            @if ($actual)
              <div class="n">{{ number_format((float) ($total['hours'] ?? 0), 2) }}h</div>
              <div class="d">
                ${{ number_format((float) ($total['cost'] ?? 0), 2) }}
                @if (($total['unapproved'] ?? 0) > 0)
                  <span class="chip crit">{{ $total['unapproved'] }} unapproved</span>
                @endif
                @if (($total['open_punches'] ?? 0) > 0)
                  <span class="chip warn">still in</span>
                @endif
              </div>
            @else
              <div class="n">{{ number_format((float) ($plannedRow['hours'] ?? 0), 2) }}h</div>
              <div class="d">${{ number_format((float) ($plannedRow['cost'] ?? 0), 2) }}</div>
            @endif
          </td>
        </tr>
      @endforeach

      @unless ($actual)
        {{-- Open shifts get their own row so a shift can be dragged off a person
             entirely, which is how you un-assign without opening a form. There is
             no counterpart on the actual side: a punch is always somebody. --}}
        <tr>
          <td class="wk-name">
            <div class="n" style="color:var(--text-3)">— open shifts —</div>
            <div class="d">employee_id IS NULL</div>
          </td>
          @foreach ($days as $d)
            <td class="wk-cell" data-employee="" data-date="{{ $d }}">
              @foreach ($cell('open', $d) as $s)
                @include('board._shift-chip', ['s' => $s, 'hhmm' => $hhmm])
              @endforeach
            </td>
          @endforeach
          <td class="wk-total"></td>
        </tr>
      @endunless
    </tbody>
  </table>

  @if ($actual)
    <div class="legend">
      <span><i class="key" style="background:var(--actual-soft);border:1px solid var(--actual)"></i>approved</span>
      <span><i class="key" style="background:var(--warn-soft);border:1px solid var(--warn)"></i>worked, not approved</span>
      <span><i class="key" style="border:1px dashed var(--actual)"></i>still clocked in — no hours yet</span>
      <span>⚠ no planned shift behind the hours</span>
    </div>
    <p class="note" style="margin-top:10px">
      <strong>✓</strong> approves one person's hours and pushes the approval to TCP.
      <strong>⋯</strong> opens the correction dialog, which is also where a punch is deleted.
      A correction clears the approval unless you say otherwise, so hours nobody has looked at
      since cannot sit there signed off.
    </p>
  @else
    <p class="note" style="margin-top:10px">
      <strong>Drag</strong> a shift to another day or person to move it.
      <strong>Hold Ctrl (or Alt)</strong> while dropping to copy it instead, leaving the original.
      A shift with punches already reconciled against it cannot be moved — copy it, or delete the punches first.
    </p>
  @endif
</div>

<div id="drag-flash" class="flash err" hidden style="position:sticky;bottom:12px"></div>

@if ($actual)
{{-- ── correction dialog ─────────────────────────────────────────────────
     One dialog for the whole grid, repopulated from the chip that opened it.
     A copy per punch would be a hundred copies of the same markup on a busy
     week, and a hundred places for them to drift apart. --}}
<dialog id="seg-dialog" class="card" style="padding:0;border:1px solid var(--line-2);max-width:520px">
  <div style="padding:16px;display:flex;flex-direction:column;gap:12px">
    <div>
      <div class="lbl">Correct actual hours</div>
      <div style="font-family:var(--mono);font-size:13px;font-weight:700;margin-top:3px" id="seg-title">—</div>
      <div class="note" style="margin-top:2px" id="seg-current">—</div>
    </div>

    <form method="POST" id="seg-form" style="margin:0;display:flex;flex-direction:column;gap:12px">
      @csrf @method('PUT')
      <input type="hidden" name="date" id="seg-date">

      <div class="ctl" style="gap:10px">
        <label class="f"><span class="lbl">Clocked in</span>
          <input type="time" name="time_in" id="seg-in" required></label>
        <label class="f"><span class="lbl">Clocked out</span>
          <input type="time" name="time_out" id="seg-out"></label>
        <label class="f"><span class="lbl">Approval</span>
          <select name="reapprove" id="seg-reapprove">
            <option value="0">clear it — these hours need reviewing again</option>
            <option value="1">keep approved</option>
          </select>
        </label>
      </div>

      <div id="seg-hint" class="note" style="border-left:3px solid var(--line-2);padding-left:9px">—</div>

      <p class="note" style="margin:0">
        Saved here first, then pushed to TCP as <code>PUT /worksegments/{id}</code> by a queued job —
        so a TCP outage never blocks a manager fixing a clock. Filling in a clock-out on an open punch
        closes it; emptying it does not re-open a closed one.
      </p>

      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" onclick="document.getElementById('seg-dialog').close()">Cancel</button>
        <button class="primary" type="submit">Save &amp; push to TCP</button>
      </div>
    </form>

    {{-- Its own form: a delete cannot be a submit button inside the correction
         form without carrying the corrected times with it. --}}
    <form method="POST" id="seg-delete" style="margin:0;border-top:1px solid var(--line);padding-top:10px;
          display:flex;gap:10px;align-items:center;justify-content:space-between">
      @csrf @method('DELETE')
      <span class="note" style="margin:0">
        Deleting soft-deletes the punch and sends <code>DEL /worksegments/{id}</code>.
        The hours stay recoverable — a punch is evidence — and the next TCP pull will not resurrect it.
      </span>
      <button class="danger" type="submit">Delete punch</button>
    </form>
  </div>
</dialog>

<script>
(() => {
  const dlg = document.getElementById('seg-dialog');
  const form = document.getElementById('seg-form');
  const del = document.getElementById('seg-delete');
  const inEl = document.getElementById('seg-in');
  const outEl = document.getElementById('seg-out');
  const hint = document.getElementById('seg-hint');
  const reapprove = document.getElementById('seg-reapprove');

  let breakMin = 0;

  const toMin = v => { const [h, m] = v.split(':').map(Number); return h * 60 + m; };

  document.querySelectorAll('.seg-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      const chip = btn.closest('.chip-seg');

      form.action = chip.dataset.updateUrl;
      del.action = chip.dataset.deleteUrl;
      document.getElementById('seg-date').value = chip.dataset.date;
      document.getElementById('seg-title').textContent =
        chip.dataset.who + ' · punch #' + chip.dataset.seg;

      document.getElementById('seg-current').textContent =
        (chip.dataset.open === '1'
          ? 'Clocked in at ' + chip.dataset.in + ' and still in the store.'
          : chip.dataset.in + '–' + chip.dataset.out + ', ' + chip.dataset.hours + ' h, ' +
            (chip.dataset.approved === '1' ? 'approved' : 'not approved yet') + '.') +
        ' TCP ' + (chip.dataset.tcp ? '#' + chip.dataset.tcp : 'id not issued yet') + '.';

      inEl.value = chip.dataset.in;
      outEl.value = chip.dataset.out || '';
      breakMin = Number(chip.dataset.break || 0);

      // Default to clearing the approval, because that is what the service does
      // unless told otherwise — the dropdown should not say something the save
      // will then contradict.
      reapprove.value = '0';
      // Nothing to keep on a punch that was never approved.
      reapprove.disabled = chip.dataset.approved !== '1';

      updateHint();
      dlg.showModal();
    });
  });

  function updateHint() {
    if (!inEl.value) { hint.textContent = '—'; return; }

    if (!outEl.value) {
      hint.innerHTML = '<strong>Open punch</strong> — clocked in at ' + inEl.value +
        ', still in the store. It has no hours, so it cannot be approved until it is closed.';
      return;
    }

    let start = toMin(inEl.value);
    let end = toMin(outEl.value);

    // Mirror the server: a clock-out before the clock-in is the punch running
    // past midnight, not an error.
    const crossed = end < start;
    if (crossed) end += 1440;

    const paid = Math.max(0, end - start - breakMin);

    hint.innerHTML = '<strong>' + (paid / 60).toFixed(2) + ' paid hours</strong>' +
      (breakMin > 0 ? ' after a ' + breakMin + ' minute break' : '') + '.' +
      (crossed ? ' <span style="color:var(--warn)">Clocks out after midnight,' +
        ' on the day after this one.</span>' : '');
  }

  inEl.addEventListener('input', updateHint);
  outEl.addEventListener('input', updateHint);
})();
</script>
@else
<script>
(() => {
  const csrf = @json(csrf_token());
  const flash = document.getElementById('drag-flash');
  let dragged = null;

  const show = msg => { flash.textContent = msg; flash.hidden = false; };

  document.querySelectorAll('.chip-shift').forEach(chip => {
    chip.addEventListener('dragstart', ev => {
      // Say why up front rather than letting the server refuse it after a
      // round trip. A published shift needs unpublishing; a worked one can only
      // be copied.
      if (chip.dataset.locked === '1') {
        ev.preventDefault();
        show(`Shift #${chip.dataset.shift} is ${chip.dataset.why}.`);
        return;
      }
      dragged = chip;
      // Firefox will not start a drag without data on the transfer.
      ev.dataTransfer.setData('text/plain', chip.dataset.shift);
      ev.dataTransfer.effectAllowed = 'copyMove';
      chip.classList.add('dragging');
    });
    chip.addEventListener('dragend', () => {
      chip.classList.remove('dragging');
      document.querySelectorAll('.wk-cell.over').forEach(c => c.classList.remove('over'));
      dragged = null;
    });
  });

  document.querySelectorAll('.wk-cell').forEach(cellEl => {
    cellEl.addEventListener('dragover', ev => {
      if (!dragged) return;
      ev.preventDefault();
      // The cursor tells the truth about which action will run.
      ev.dataTransfer.dropEffect = (ev.ctrlKey || ev.altKey) ? 'copy' : 'move';
      cellEl.classList.add('over');
    });
    cellEl.addEventListener('dragleave', () => cellEl.classList.remove('over'));

    cellEl.addEventListener('drop', async ev => {
      ev.preventDefault();
      cellEl.classList.remove('over');
      if (!dragged) return;

      const shiftId = dragged.dataset.shift;
      const copy = ev.ctrlKey || ev.altKey;
      const employee = cellEl.dataset.employee;

      // Dropping a shift back where it already is should not write a row.
      if (!copy && dragged.closest('.wk-cell') === cellEl) return;

      // A worked shift can be copied but not moved. Say so here rather than
      // spending a round trip to be told the same thing.
      if (!copy && Number(dragged.dataset.worked) > 0) {
        show(`Shift #${shiftId} has ${dragged.dataset.worked} punch(es) against it — hold Ctrl to copy it instead.`);
        return;
      }

      const body = { business_date: cellEl.dataset.date };
      if (employee === '') body.unassign = true; else body.employee_id = Number(employee);

      try {
        const res = await fetch(`/board/shifts/${shiftId}/${copy ? 'copy' : 'move'}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
          },
          body: JSON.stringify(body),
        });
        const payload = await res.json().catch(() => ({}));

        if (res.ok && payload.ok) {
          // Reload rather than patch the DOM: the server owns cost totals,
          // availability flags and the conflict warnings, and re-deriving them
          // here would be a second implementation to keep in step.
          location.reload();
          return;
        }
        show(payload.message ?? `Drop refused (HTTP ${res.status}).`);
      } catch (e) {
        show('Drop failed: ' + e.message);
      }
    });
  });
})();
</script>
@endif
@endsection
