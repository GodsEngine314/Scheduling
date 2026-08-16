<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Schedule console')</title>
<style>
  :root{
    --bg:#EFEFF3; --surface:#FFFFFF; --surface-2:#F7F7FA; --sunken:#E7E7EE;
    --line:#DCDCE4; --line-2:#C3C3D1;
    --text:#1A1A22; --text-2:#585866; --text-3:#8A8A99;
    --planned:#6E63D2; --planned-soft:#E9E7FA; --planned-ink:#2E2870;
    --actual:#12876B; --actual-soft:#DDF1EB; --actual-ink:#0A4437;
    --warn:#A96C08; --warn-soft:#FAEFD9; --crit:#AC3831; --crit-soft:#FAE7E5;
    --ok:#2C7A58; --ok-soft:#DDF1EB;
    --mono:ui-monospace,"SF Mono","Cascadia Mono",Menlo,Consolas,monospace;
    --sans:ui-sans-serif,system-ui,"Segoe UI",Roboto,sans-serif;
  }
  @media (prefers-color-scheme:dark){
    :root{
      --bg:#131419; --surface:#1B1C23; --surface-2:#22232C; --sunken:#101116;
      --line:#2E3039; --line-2:#3F4251;
      --text:#E9E9F0; --text-2:#A2A2B1; --text-3:#73738A;
      --planned:#9A91F0; --planned-soft:#2A2560; --planned-ink:#D2CDFA;
      --actual:#3FBF9B; --actual-soft:#0D3D32; --actual-ink:#A2E5D0;
      --warn:#E0A340; --warn-soft:#3A2B10; --crit:#E5766E; --crit-soft:#3C1B18;
      --ok:#4FC08D; --ok-soft:#0D3D32;
    }
  }
  *{box-sizing:border-box}
  body{margin:0;padding:20px;background:var(--bg);color:var(--text);
       font-family:var(--sans);font-size:13px;line-height:1.5;
       font-variant-numeric:tabular-nums}
  h1,h2{margin:0;font-family:var(--mono);font-weight:700}
  a{color:var(--planned)}
  .wrap{display:flex;flex-direction:column;gap:14px;max-width:1500px;margin:0 auto}
  .lbl{font-family:var(--mono);font-size:9.5px;font-weight:700;letter-spacing:.11em;
       text-transform:uppercase;color:var(--text-3)}
  .card{background:var(--surface);border:1px solid var(--line);border-radius:6px}
  .pad{padding:12px 14px}
  .row-flex{display:flex;flex-wrap:wrap;gap:12px;align-items:stretch}
  .grow{flex:1 1 260px;min-width:260px}
  .stat{flex:0 0 auto;min-width:130px;display:flex;flex-direction:column;justify-content:center}
  .stat .v{font-family:var(--mono);font-size:19px;font-weight:700;line-height:1.15}
  .stat .s{font-family:var(--mono);font-size:10px;color:var(--text-3)}

  form.inline{display:inline}
  .ctl{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
  .f{display:flex;flex-direction:column;gap:3px}
  select,input{font-family:var(--mono);font-size:12px;padding:5px 7px;border-radius:4px;
    border:1px solid var(--line-2);background:var(--surface-2);color:var(--text);min-height:29px}
  input[type=time],input[type=date]{width:auto}
  input.num{width:70px}
  button{font-family:var(--mono);font-size:11px;font-weight:700;letter-spacing:.03em;cursor:pointer;
    padding:6px 11px;border-radius:4px;border:1px solid var(--line-2);
    background:var(--surface-2);color:var(--text);min-height:29px}
  button:hover{background:var(--sunken);border-color:var(--text-3)}
  button:focus-visible{outline:2px solid var(--planned);outline-offset:2px}
  button.primary{background:var(--planned-soft);border-color:var(--planned);color:var(--planned-ink)}
  button.primary:hover{background:var(--planned);color:var(--surface)}
  button.mini{padding:2px 6px;font-size:10px;min-height:0}
  input.tiny{width:50px;padding:1px 4px;font-size:10px;min-height:0}
  dialog{background:var(--surface);color:var(--text);border-radius:8px}
  dialog::backdrop{background:rgba(0,0,0,.45)}

  /* week grid */
  table.week{table-layout:fixed;min-width:1000px}
  table.week th{padding:5px 8px}
  td.wk-name,th.wk-name{width:150px;white-space:normal}
  .wk-name .n{font-family:var(--mono);font-size:11.5px;font-weight:700;color:var(--text)}
  .wk-name .d{font-family:var(--mono);font-size:9.5px;color:var(--text-3)}
  td.wk-cell{vertical-align:top;height:74px;padding:4px;background:var(--sunken);
    border:1px solid var(--line);white-space:normal}
  td.wk-cell.over{background:var(--planned-soft);border-color:var(--planned);border-style:dashed}
  .chip-shift{display:block;margin-bottom:3px;padding:3px 5px;border-radius:4px;cursor:grab;
    background:var(--planned-soft);border:1px solid var(--planned);color:var(--planned-ink);
    font-family:var(--mono);font-size:9.5px;line-height:1.35}
  .chip-shift:active{cursor:grabbing}
  .chip-shift.dragging{opacity:.4}
  .chip-shift.bad{border-style:dashed;border-color:var(--crit)}
  .chip-shift.locked{cursor:not-allowed;background:var(--surface-2);border-style:dotted}
  .chip-shift.published{border-style:solid;border-color:var(--ok);color:var(--actual-ink);
    background:var(--ok-soft)}
  .chip-shift .t{display:block;font-weight:700}
  .chip-shift .m{display:block;color:var(--text-3)}

  /* the week grid, actual side. Green is signed off, amber is work waiting for
     somebody, and a dashed outline is a punch that has not finished happening. */
  td.wk-cell.actual{background:var(--surface-2)}
  .chip-seg{display:block;margin-bottom:3px;padding:3px 5px;border-radius:4px;
    background:var(--actual-soft);border:1px solid var(--actual);color:var(--actual-ink);
    font-family:var(--mono);font-size:9.5px;line-height:1.35}
  /* The colour says whether the punch is WHOLE — that is what you scan a week
     for. Approval is the smaller mark below, because a signed-off punch and an
     unsigned one are both real records of hours worked, while a punch missing
     half of itself is a hole in the timesheet. */
  .chip-seg.done{background:var(--actual-soft);border-color:var(--actual);color:var(--actual-ink)}
  /* In the store right now. Green like a whole punch — nothing is wrong with
     it — but hatched and dashed so it reads as unfinished rather than done. */
  .chip-seg.open{background:repeating-linear-gradient(135deg,var(--actual-soft),
    var(--actual-soft) 4px,transparent 4px,transparent 8px);border-style:dashed}
  /* Missed a clock-out, or never clocked in at all. Amber: somebody has to fix
     this before the week's hours mean anything. */
  .chip-seg.missed{background:var(--warn-soft);border-color:var(--warn);color:var(--warn);
    border-style:dashed}
  /* No punch exists at all, so there is nothing to click. */
  .chip-seg.missing-in{opacity:.92}
  /* Signed off. A quiet left edge, not a background — see above. */
  .chip-seg.is-approved{box-shadow:inset 2px 0 0 var(--ok)}

  /* The combined cell: plan above, worked below, separated by a rule rather
     than by colour alone — two chip colours do not survive a split shift
     stacked under a split plan, which is the case the split tabs existed to
     avoid. Wider, because it now holds two stacks. */
  td.wk-cell.stacked{min-width:132px}
  .cell-rule{height:0;margin:4px 0;border-top:1px dashed var(--line-2)}
  .chip-seg .t{display:block;font-weight:700}
  .chip-seg .m{display:flex;gap:4px;align-items:center;justify-content:space-between;
    color:var(--text-3);margin-top:1px}
  .chip-seg .acts{display:flex;gap:2px;flex:0 0 auto}
  .chip-seg .acts button{padding:0 4px;font-size:9px;min-height:15px;line-height:1.4}
  td.wk-total,th.wk-total{width:110px;white-space:normal;vertical-align:top;padding:4px 8px}
  .wk-total .n{font-family:var(--mono);font-size:11.5px;font-weight:700;color:var(--text)}
  .wk-total .d{font-family:var(--mono);font-size:9.5px;color:var(--text-3);
    display:flex;flex-wrap:wrap;gap:3px;margin-top:2px}

  .topbar{display:flex;flex-wrap:wrap;gap:12px 20px;align-items:center;justify-content:space-between}
  .topbar-nav{display:flex;gap:4px}
  .topbar-nav a{font-family:var(--mono);font-size:11px;font-weight:700;letter-spacing:.03em;
    text-decoration:none;color:var(--text-2);padding:5px 10px;border-radius:4px;
    border:1px solid transparent}
  .topbar-nav a:hover{background:var(--surface-2);border-color:var(--line-2)}
  .topbar-nav a.on{background:var(--planned-soft);color:var(--planned-ink);border-color:var(--planned)}
  button.danger:hover{border-color:var(--crit);color:var(--crit)}

  .flash{border-radius:6px;padding:9px 12px;font-family:var(--mono);font-size:11.5px;
         border:1px solid transparent}
  .flash.ok{background:var(--ok-soft);color:var(--actual-ink);border-color:var(--actual)}
  .flash.err{background:var(--crit-soft);color:var(--crit);border-color:var(--crit)}

  .chip{display:inline-flex;align-items:center;font-family:var(--mono);font-size:9.5px;
    font-weight:700;padding:1px 6px;border-radius:999px;white-space:nowrap}
  .chip.ok{background:var(--actual-soft);color:var(--actual-ink)}
  .chip.warn{background:var(--warn-soft);color:var(--warn)}
  .chip.crit{background:var(--crit-soft);color:var(--crit)}
  .chip.neutral{background:var(--sunken);color:var(--text-2)}
  .chip.plan{background:var(--planned-soft);color:var(--planned-ink)}

  .tbl-wrap{overflow-x:auto}
  table{border-collapse:collapse;width:100%;font-family:var(--mono);font-size:10.5px;min-width:620px}
  caption{text-align:left;padding:0 0 6px;font-family:var(--mono);font-size:10px;font-weight:700;
    letter-spacing:.09em;text-transform:uppercase;color:var(--text-2)}
  th{text-align:left;font-weight:700;color:var(--text-3);font-size:9px;letter-spacing:.06em;
    text-transform:uppercase;padding:4px 8px;border-bottom:1px solid var(--line-2);white-space:nowrap}
  td{padding:4px 8px;border-bottom:1px solid var(--line);white-space:nowrap;color:var(--text-2);
     vertical-align:top}
  td.k{color:var(--text);font-weight:700}
  td.null{color:var(--text-3);font-style:italic}
  tbody tr:hover td{background:var(--surface-2)}
  .empty{padding:10px;color:var(--text-3);font-family:var(--mono);font-size:10.5px}
  .note{font-size:11.5px;color:var(--text-2);max-width:80ch}
  code{font-family:var(--mono);font-size:10.5px;background:var(--sunken);padding:.5px 3px;border-radius:3px}

  /* timeline */
  .tl{min-width:900px}
  .tl-head{display:flex;border-bottom:1px solid var(--line);padding-bottom:4px;margin-bottom:6px}
  .tl-name{flex:0 0 170px}
  .tl-track{flex:1;position:relative;height:14px}
  .tick{position:absolute;top:0;font-family:var(--mono);font-size:9px;color:var(--text-3);
    transform:translateX(-50%)}
  .lane-row{display:flex;align-items:center;border-bottom:1px solid var(--line);padding:7px 0}
  .lane-row:last-child{border-bottom:0}
  .who{flex:0 0 170px;padding-right:12px}
  .who .n{font-family:var(--mono);font-size:12px;font-weight:700}
  .who .d{font-family:var(--mono);font-size:9.5px;color:var(--text-3);display:flex;gap:5px;
          flex-wrap:wrap;margin-top:2px}
  .lane{flex:1;position:relative;height:46px;background:var(--sunken);border-radius:3px;overflow:hidden}
  .grid i{position:absolute;top:0;bottom:0;width:1px;background:var(--line)}
  .avail{position:absolute;top:0;height:100%;background:var(--surface);
         border-left:1px solid var(--line-2);border-right:1px solid var(--line-2)}
  .bar{position:absolute;border-radius:3px;font-family:var(--mono);font-size:9.5px;font-weight:700;
    display:flex;align-items:center;padding:0 5px;overflow:hidden;white-space:nowrap;
    border:1px solid transparent}
  .bar.plan{top:5px;height:21px;background:var(--planned-soft);color:var(--planned-ink);
            border-color:var(--planned)}
  .bar.plan.bad{border-style:dashed;border-color:var(--crit)}
  .bar.plan.open{background:transparent;border-style:dashed;color:var(--planned)}
  .bar.seg{top:30px;height:12px;background:var(--actual-soft);color:var(--actual-ink);
           border-color:var(--actual);font-size:9px}
  .bar.seg.unmatched{border-style:dotted}
  .bar.seg.openpunch{background:repeating-linear-gradient(135deg,var(--actual-soft),
    var(--actual-soft) 4px,transparent 4px,transparent 8px)}
  .splitlink{position:absolute;top:15px;height:0;border-top:2px dotted var(--planned)}
  .legend{display:flex;flex-wrap:wrap;gap:6px 14px;font-family:var(--mono);font-size:10px;
          color:var(--text-3);margin-top:10px}
  .key{display:inline-block;width:16px;height:9px;border-radius:2px;margin-right:4px;
       vertical-align:middle}
</style>
</head>
<body>
<div class="wrap">

  {{-- ── who is signed in ───────────────────────────────────────────────
       A real identity now: the auth service verified the token this request
       carried, and it is what every created_by_user_id is stamped with. The
       roles shown are whatever the authority said on THIS request, not a copy
       kept in the session. --}}
  @php
      $auth = app(\App\Support\AuthContext::class);
      // Parenthesised deliberately: && binds tighter than ??, so without them
      // this reads the array key directly and blows up on every ordinary
      // session, where there is no dev_bypass key at all.
      $viaDevBypass = app(\App\Services\Auth\DevBypass::class)->enabled()
          && ($auth->current()->raw['dev_bypass'] ?? false);
  @endphp

  @if ($viaDevBypass)
    {{-- Deliberately loud and on every page. A bypassed session looks exactly
         like a real one everywhere else, and that is the point — but nobody
         should be able to forget which one they are in. --}}
    <div class="flash err" style="margin-bottom:12px">
      <strong>LOCAL DEVELOPMENT BYPASS.</strong> Nobody checked a credential for this session,
      and it holds super-admin. Set <code>AUTH_SERVICE_DEV_BYPASS=false</code> to require a real sign-in.
    </div>
  @endif
  <div class="card pad topbar">
    <nav class="topbar-nav">
      <a href="{{ route('board') }}" class="{{ request()->routeIs('board') ? 'on' : '' }}">Day</a>
      <a href="{{ route('board.week') }}" class="{{ request()->routeIs('board.week') ? 'on' : '' }}">Week</a>
      <a href="{{ route('board.settings') }}" class="{{ request()->routeIs('board.settings') ? 'on' : '' }}">Settings</a>
    </nav>

    <div class="ctl" style="gap:10px;align-items:center">
      <span class="lbl">Signed in</span>
      <span style="font-family:var(--mono);font-size:11.5px">{{ $auth->name() }}</span>

      @if ($auth->roles() !== [])
        <span class="chip {{ $auth->isSuperAdmin() ? 'warn' : 'neutral' }}">{{ implode(', ', $auth->roles()) }}</span>
      @endif

      @if ($auth->userId() === null && $auth->isAuthenticated())
        {{-- Authenticated, but the users PROJECTION has not caught up with the
             auth.v1.user.created event yet, so changes cannot be attributed to a
             row that is not there. It resolves itself when the event lands. --}}
        <span class="chip crit" title="The auth service knows this user; the local projection does not yet, so changes will be recorded unattributed">not projected yet</span>
      @endif

      <form method="POST" action="{{ route('logout') }}" class="inline">
        @csrf<button class="mini">Sign out</button>
      </form>
    </div>
  </div>

  @if (session('ok'))   <div class="flash ok">{{ session('ok') }}</div>   @endif
  @if (session('err'))  <div class="flash err">{{ session('err') }}</div> @endif
  @if ($errors->any())
    <div class="flash err">{{ $errors->first() }}</div>
  @endif
  @yield('content')
</div>
</body>
</html>
