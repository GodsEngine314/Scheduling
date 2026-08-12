{{-- The record of what happened. $entries = ActivityLog collection --}}
<div class="card pad">
  <div class="tbl-wrap">
    <table>
      <caption>{{ $heading ?? 'Activity' }}
        <em style="font-style:normal;color:var(--text-3);text-transform:none;letter-spacing:0">
          — activity_logs, append-only
        </em>
      </caption>
      <thead><tr>
        <th>when</th><th>who</th><th>action</th><th>subject</th><th>for</th><th>what changed</th>
      </tr></thead>
      <tbody>
      @forelse ($entries as $a)
        <tr>
          <td>{{ $a->created_at?->format('D j M H:i') }}</td>
          <td class="k">
            {{ $a->actor_name }}
            @if ($a->user_id === null && $a->actor_name !== 'Unattributed')
              <span class="chip neutral" title="The auth user has since been removed; the name is the snapshot taken at the time">gone</span>
            @endif
          </td>
          <td>
            <span class="chip {{ $a->action->isDestructive() ? 'crit' : 'neutral' }}">{{ $a->action->label() }}</span>
          </td>
          <td>{{ $a->subject_type->label() }}{{ $a->subject_id ? ' #'.$a->subject_id : '' }}</td>
          <td>{{ $a->business_date?->toDateString() ?? '—' }}</td>
          <td style="white-space:normal;max-width:420px">
            {{ $a->summariseChanges() ?: '—' }}
            @if ($a->context)
              <div style="color:var(--text-3);font-size:9.5px">
                @foreach ($a->context as $k => $v)
                  @if ($v !== null && $v !== [])
                    {{ $k }}={{ is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'yes' : 'no') : $v) }}@if (!$loop->last) · @endif
                  @endif
                @endforeach
              </div>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="6" class="empty">Nothing recorded yet.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
