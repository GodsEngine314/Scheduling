{{-- Deliberately NOT extending layouts.console: that layout renders the store
     picker, the nav and the acting-as switcher, none of which mean anything to
     somebody who is not signed in yet, and its @php block queries users. --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — Scheduling</title>
<style>
  :root{
    --bg:#EFEFF3; --surface:#FFFFFF; --line:#DCDCE4; --line-2:#C3C3D1;
    --text:#1A1A22; --text-2:#585866; --text-3:#8A8A99;
    --planned:#6E63D2; --crit:#AC3831; --crit-soft:#FAE7E5;
    --ok:#2C7A58; --ok-soft:#DDF1EB; --actual:#12876B;
    --mono:ui-monospace,"SF Mono","Cascadia Mono",Menlo,Consolas,monospace;
    --sans:ui-sans-serif,system-ui,"Segoe UI",Roboto,sans-serif;
  }
  @media (prefers-color-scheme:dark){
    :root{
      --bg:#131419; --surface:#1B1C23; --line:#2E3039; --line-2:#3F4251;
      --text:#E9E9F0; --text-2:#A2A2B1; --text-3:#73738A;
      --planned:#9A91F0; --crit:#E5766E; --crit-soft:#3C1B18;
      --ok:#4FC08D; --ok-soft:#0D3D32; --actual:#3FBF9B;
    }
  }
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;
    background:var(--bg);color:var(--text);font-family:var(--sans);font-size:13px;line-height:1.5}
  .card{width:100%;max-width:380px;background:var(--surface);border:1px solid var(--line);
    border-radius:10px;padding:22px}
  h1{margin:0 0 2px;font-size:15px;letter-spacing:-0.01em}
  .lbl{font-family:var(--mono);font-size:10px;text-transform:uppercase;
    letter-spacing:0.08em;color:var(--text-3)}
  .note{margin:0;color:var(--text-2);font-size:11.5px}
  label{display:block;margin-top:14px}
  input{width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--line-2);
    border-radius:6px;background:var(--bg);color:var(--text);font:inherit}
  input:focus{outline:2px solid var(--planned);outline-offset:1px}
  button{margin-top:18px;width:100%;padding:9px 12px;border:1px solid var(--planned);
    border-radius:6px;background:var(--planned);color:#fff;font:inherit;font-weight:600;cursor:pointer}
  button:hover{filter:brightness(1.06)}
  .flash{margin-bottom:14px;border:1px solid;border-radius:6px;padding:9px 12px;
    font-family:var(--mono);font-size:11px}
  .flash.err{background:var(--crit-soft);color:var(--crit);border-color:var(--crit)}
  .flash.ok{background:var(--ok-soft);color:var(--actual);border-color:var(--actual)}
  footer{margin-top:16px;padding-top:12px;border-top:1px solid var(--line);
    color:var(--text-3);font-size:10.5px}
</style>
</head>
<body>
  <form method="POST" action="{{ route('login.store') }}" class="card">
    @csrf

    <div class="lbl">Scheduling</div>
    <h1>Sign in</h1>
    <p class="note">Your credentials are checked by the authentication service, not here.</p>

    <div style="margin-top:16px">
      @if (session('err')) <div class="flash err">{{ session('err') }}</div> @endif
      @if (session('ok'))  <div class="flash ok">{{ session('ok') }}</div>  @endif
      @if ($errors->any()) <div class="flash err">{{ $errors->first() }}</div> @endif
    </div>

    <label>
      <span class="lbl">Email</span>
      <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
    </label>

    <label>
      <span class="lbl">Password</span>
      <input type="password" name="password" required autocomplete="current-password">
    </label>

    <button>Sign in</button>

    @if ($devBypass ?? false)
      <div class="flash err" style="margin-top:16px;margin-bottom:0">
        <strong>LOCAL DEVELOPMENT BYPASS IS ON.</strong><br>
        <code>{{ config('auth_service.dev_bypass.username') }}</code> /
        <code>{{ config('auth_service.dev_bypass.password') }}</code>
        signs in as super-admin without contacting the authentication service.
        Turn it off with <code>AUTH_SERVICE_DEV_BYPASS=false</code>.
      </div>
    @endif

    <footer>
      Scheduling stores no passwords. It holds the token the authentication
      service issues, and asks that service what the token may do on every
      request.
    </footer>
  </form>
</body>
</html>
