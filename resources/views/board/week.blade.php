@extends('layouts.console')
@section('title', 'Week — store '.$storeId.' — '.$weekStart)

@php
    use App\Support\BusinessDay;

    $bd = app(BusinessDay::class);
    $hhmm = fn ($instant): string => $instant === null ? '—'
        : $bd->toLocal($storeId, $instant)->format('H:i');

    $prevWeek = \Carbon\CarbonImmutable::parse($weekStart)->subWeek()->toDateString();
    $nextWeek = \Carbon\CarbonImmutable::parse($weekStart)->addWeek()->toDateString();

    // Cells are addressed by "employee id or the string open" + date, which is
    // exactly what the drop handler posts back.
    $cell = fn ($employeeId, string $day) => data_get($byCell, [(string) $employeeId, $day], collect());
@endphp

@section('content')

<div class="row-flex">
  <div class="card pad grow">
    <h1>Store #{{ $storeId }} · week of {{ \Carbon\Carbon::parse($weekStart)->format('j M Y') }}</h1>
    <div class="lbl" style="margin-top:4px">{{ $timezone }}</div>
    <form method="GET" action="{{ route('board.week') }}" class="ctl" style="margin-top:10px">
      <label class="f"><span class="lbl">Store</span>
        <select name="store">
          @foreach ($stores as $s)
            <option value="{{ $s->id }}" @selected($s->id === $storeId)>#{{ $s->id }} — {{ $s->store_number }}</option>
          @endforeach
        </select>
      </label>
      <label class="f"><span class="lbl">Week of</span><input type="date" name="week" value="{{ $weekStart }}"></label>
      <button>Go</button>
      <a href="{{ route('board.week', ['store' => $storeId, 'week' => $prevWeek]) }}"><button type="button">‹ prev</button></a>
      <a href="{{ route('board.week', ['store' => $storeId, 'week' => $nextWeek]) }}"><button type="button">next ›</button></a>
    </form>
  </div>

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
</div>

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

{{-- ── the grid ───────────────────────────────────────────────────────── --}}
<div class="card pad" style="overflow-x:auto">
  <div class="lbl" style="margin-bottom:8px">The week</div>

  <table class="week">
    <thead>
      <tr>
        <th class="wk-name">Employee</th>
        @foreach ($days as $d)
          <th>{{ \Carbon\Carbon::parse($d)->format('D') }}
            <span style="color:var(--text-3);font-weight:400">{{ \Carbon\Carbon::parse($d)->format('j M') }}</span>
          </th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @foreach ($roster as $r)
        @php $e = $r['model']; @endphp
        <tr>
          <td class="wk-name">
            <div class="n">{{ $e->fullName() }}</div>
            <div class="d">{{ $r['rate'] !== null ? '$'.number_format($r['rate'], 2).'/h' : 'no rate' }}</div>
          </td>
          @foreach ($days as $d)
            <td class="wk-cell" data-employee="{{ $e->id }}" data-date="{{ $d }}">
              @foreach ($cell($e->id, $d) as $s)
                @include('board._shift-chip', ['s' => $s, 'hhmm' => $hhmm])
              @endforeach
            </td>
          @endforeach
        </tr>
      @endforeach

      {{-- Open shifts get their own row so a shift can be dragged off a person
           entirely, which is how you un-assign without opening a form. --}}
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
      </tr>
    </tbody>
  </table>

  <p class="note" style="margin-top:10px">
    <strong>Drag</strong> a shift to another day or person to move it.
    <strong>Hold Ctrl (or Alt)</strong> while dropping to copy it instead, leaving the original.
    A shift with punches already reconciled against it cannot be moved — copy it, or delete the punches first.
  </p>
</div>

@include('board._activity', ['entries' => $activity, 'heading' => 'Activity this week'])

<div id="drag-flash" class="flash err" hidden style="position:sticky;bottom:12px"></div>

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
          // availability flags and the activity list, and re-deriving them
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
@endsection
