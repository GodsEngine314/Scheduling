{{-- Actual hours, arriving on their own.
     $storeId, $from, $to, $live (a LiveSegmentFeed snapshot) and $headline
     from the parent view. One card per board: the headline carries whatever the
     board used to say about TCP, so this does not sit next to a second card
     about the same thing.

     THIS REPLACED A BUTTON. "Pull the week's actual hours" made the board's
     currency a chore somebody had to remember, and a stale grid looks exactly
     as settled as a current one — so forgetting was invisible. A punch is an
     event at the timeclock and the board is a window onto it, so the window
     keeps itself open.

     WHAT THIS CARD IS FOR. Not decoration: the polling is silent, so without a
     readout there is no way to tell "nothing has changed" from "nothing is
     being checked". It states when TCP was last read, and it goes amber and
     says why when that stops working. The one thing it must never do is look
     healthy while it is not.

     THERE IS NO PUSH FROM TCP. No webhook, no subscription — GET /worksegments
     is the entire surface. So the floor on "as soon as it appears" is how often
     we ask, which is config('tcp.live') and nothing cleverer. --}}
<div class="card pad grow live-card" style="border-left:4px solid var(--actual)"
     data-live-url="{{ route('board.live') }}"
     data-live-store="{{ $storeId }}"
     data-live-from="{{ $from }}"
     data-live-to="{{ $to }}"
     data-live-fingerprint="{{ $live['fingerprint'] }}"
     data-live-poll="{{ $live['poll_seconds'] }}"
     data-live-idle="{{ (int) config('tcp.live.idle_timeout_seconds', 1800) }}">
  <div class="lbl">TCP</div>
  <div class="live-head">
    <span class="live-dot" aria-hidden="true"></span>
    <span>{{ $headline }}</span>
  </div>
  <p class="note" style="margin:2px 0 0">
    <span class="live-state">Arriving on their own</span> — <span class="live-when">checking…</span>
  </p>
  {{-- Hidden until there is something to say. An empty line every render trains
       people to stop reading the card. --}}
  <p class="note live-problem" style="margin:4px 0 0;color:var(--warn);display:none"></p>
  <p class="note live-pending" style="margin:4px 0 0;color:var(--actual);display:none">
    New hours have arrived. Refreshing as soon as you are done here.
  </p>
</div>

@once
  @push('styles')
    <style>
      .live-head{display:flex;align-items:center;gap:6px;font-family:var(--mono);font-weight:700;font-size:13px;color:var(--actual)}
      .live-dot{width:8px;height:8px;border-radius:50%;background:var(--actual);flex:0 0 auto}
      /* The pulse is the only thing on the card that says work is happening
         right now. Reduced-motion users get a static dot and the words. */
      .live-card[data-live-status="checking"] .live-dot{animation:live-pulse 1s ease-in-out infinite}
      .live-card[data-live-status="warn"] .live-dot,
      .live-card[data-live-status="warn"] .live-head{color:var(--warn);background:var(--warn)}
      .live-card[data-live-status="warn"] .live-head{background:none}
      .live-card[data-live-status="paused"] .live-dot{background:var(--line-2)}
      .live-card[data-live-status="paused"] .live-head{color:var(--text-3)}
      @keyframes live-pulse{0%,100%{opacity:1}50%{opacity:.25}}
      @media (prefers-reduced-motion: reduce){.live-card .live-dot{animation:none!important}}
    </style>
  @endpush

  @push('scripts')
    <script>
    /*
     * The board's heartbeat.
     *
     * Polls board.live, which answers "has anything changed?" and refreshes the
     * visible range from TCP while it is in there. When the fingerprint moves,
     * the page reloads — this console is server-rendered by design, and the
     * chip handlers are bound per element, so swapping markup in place would
     * quietly break drag-and-drop and the correction dialogs. A reload is the
     * honest way to show new data here.
     *
     * A RELOAD MUST NEVER EAT SOMEBODY'S WORK. That is the whole of the
     * complexity below. If a dialog is open, or a field has focus, or a chip is
     * mid-drag, the reload is DEFERRED and announced, then taken at the first
     * safe moment. Losing a half-typed time correction to a background refresh
     * would be a worse bug than the stale grid this replaced.
     */
    (function () {
      var card = document.querySelector('.live-card');
      if (!card || !window.fetch) return;

      var url = card.dataset.liveUrl;
      var params = new URLSearchParams({
        store: card.dataset.liveStore,
        from: card.dataset.liveFrom,
        to: card.dataset.liveTo
      });

      var fingerprint = card.dataset.liveFingerprint;
      var basePoll = Math.max(2, parseInt(card.dataset.livePoll, 10) || 10) * 1000;
      var idleAfter = Math.max(60, parseInt(card.dataset.liveIdle, 10) || 1800) * 1000;

      var state = card.querySelector('.live-state');
      var when = card.querySelector('.live-when');
      var problem = card.querySelector('.live-problem');
      var pendingNote = card.querySelector('.live-pending');

      var timer = null;
      var failures = 0;
      var lastInteraction = Date.now();
      var reloadPending = false;
      var checkedAt = null;   // seconds-ago at the last successful poll
      var checkedLocal = 0;   // when we learned it, so the label can count up

      function status(name) { card.dataset.liveStatus = name; }

      function ago(seconds) {
        if (seconds === null || seconds === undefined) return 'not checked yet';
        if (seconds < 5) return 'checked just now';
        if (seconds < 90) return 'checked ' + Math.round(seconds) + 's ago';
        var mins = Math.round(seconds / 60);
        if (mins < 90) return 'checked ' + mins + ' min ago';
        return 'checked ' + Math.round(mins / 60) + ' h ago';
      }

      function paint() {
        if (card.dataset.liveStatus === 'paused') {
          state.textContent = 'Paused';
          when.textContent = 'idle — click to resume';
          return;
        }
        var seconds = checkedAt === null ? null : checkedAt + (Date.now() - checkedLocal) / 1000;
        when.textContent = ago(seconds);
      }

      /*
       * Is it safe to pull the rug out from under whoever is looking at this?
       *
       * A native <dialog open> covers the correction and hand-entry forms; the
       * focus check covers a filter or a date box being typed into with no
       * dialog involved; .dragging covers a chip in flight, which a reload
       * would drop on the floor mid-move.
       */
      function safeToReload() {
        if (document.querySelector('dialog[open]')) return false;
        if (document.querySelector('.dragging')) return false;

        var el = document.activeElement;
        if (el && el !== document.body) {
          var tag = (el.tagName || '').toLowerCase();
          if (tag === 'input' || tag === 'select' || tag === 'textarea' || el.isContentEditable) return false;
        }
        return true;
      }

      function refreshNow() {
        // A reload while a POST is in flight would race the redirect that POST
        // is about to produce, so the flag survives into the next page instead:
        // the fresh render simply has the new hours in it.
        window.location.reload();
      }

      function changed() {
        if (safeToReload()) { refreshNow(); return; }

        // Announced, not silent. Somebody in a dialog needs to know the grid
        // behind it has moved on, or they will save a correction against
        // numbers they can no longer see.
        reloadPending = true;
        pendingNote.style.display = '';
      }

      function tick() {
        // An abandoned tab must not keep a store's TCP sync warm all night.
        if (Date.now() - lastInteraction > idleAfter) {
          status('paused');
          paint();
          return; // No re-arm. resume() is what starts it again.
        }

        // A hidden tab is somebody else's problem until it comes back.
        if (document.hidden) { schedule(basePoll); return; }

        status('checking');
        paint();

        fetch(url + '?' + params.toString(), {
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        })
          .then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
          })
          .then(function (data) {
            failures = 0;
            checkedAt = data.checked_seconds_ago;
            checkedLocal = Date.now();

            if (data.poll_seconds) basePoll = Math.max(2, data.poll_seconds) * 1000;

            // The vendor being unreachable is not a reason to stop polling —
            // it is the reason to keep polling — but it must be visible while
            // it lasts, because the grid is frozen for as long as it does.
            var trouble = data.error || (data.skipped ? 'TCP sent rows we could not file: ' + data.skipped : null);

            if (trouble) {
              status('warn');
              problem.textContent = trouble;
              problem.style.display = '';
            } else {
              status(data.checking ? 'checking' : 'live');
              problem.style.display = 'none';
            }

            state.textContent = trouble ? 'Not reaching TCP' : 'Arriving on their own';
            paint();

            if (data.fingerprint && data.fingerprint !== fingerprint) {
              fingerprint = data.fingerprint;
              changed();
            } else if (reloadPending && safeToReload()) {
              // Nothing new this tick, but the change we were holding can go
              // through now.
              refreshNow();
            }

            schedule(basePoll);
          })
          .catch(function (e) {
            // OUR OWN endpoint failed, not the vendor's — a dropped network, a
            // restarted server, an expired session. Back off so a laptop that
            // has closed its lid does not spin, but never give up: the tab is
            // still showing a grid somebody believes is current.
            failures++;
            status('warn');
            state.textContent = 'Cannot reach the console';
            problem.textContent = 'Cannot reach the console (' + e.message + '). Still trying.';
            problem.style.display = '';
            paint();
            schedule(Math.min(basePoll * Math.pow(2, Math.min(failures, 4)), 120000));
          });
      }

      function schedule(delay) {
        if (timer) clearTimeout(timer);
        timer = setTimeout(tick, delay);
      }

      function resume() {
        lastInteraction = Date.now();
        if (card.dataset.liveStatus === 'paused') { status('live'); tick(); }
      }

      ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (event) {
        // passive + no re-arm work in the handler: this fires constantly and
        // must cost nothing.
        window.addEventListener(event, function () { lastInteraction = Date.now(); }, { passive: true });
      });

      window.addEventListener('click', resume, { passive: true });
      window.addEventListener('keydown', resume, { passive: true });

      // Coming back to the tab is the strongest signal there is that somebody
      // wants current numbers, so it checks immediately rather than waiting out
      // whatever was left of the interval.
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { lastInteraction = Date.now(); tick(); }
      });

      // Closing a dialog is the commonest moment a deferred reload becomes
      // safe. Capture, because the close event does not bubble.
      document.addEventListener('close', function () {
        if (reloadPending) setTimeout(function () { if (safeToReload()) refreshNow(); }, 0);
      }, true);

      // The label counts up between polls, so a card that has stopped being
      // updated visibly ages instead of sitting on a comfortable "checked 3s
      // ago" forever.
      setInterval(paint, 1000);

      status('live');
      paint();
      // Straight away, not after one interval: the page was rendered from
      // whatever was in the table, and this is the call that makes it current.
      schedule(500);
    })();
    </script>
  @endpush
@endonce
