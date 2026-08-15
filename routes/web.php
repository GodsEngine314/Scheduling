<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Schedule console
|--------------------------------------------------------------------------
|
| A server-rendered board for driving the domain by hand. Every action routes
| to the same service the JSON API uses, so this is a way of exercising the
| real code rather than a second implementation of it.
|
| SIGNED IN OR NOTHING. Everything except the three login routes sits behind
| auth.service, which verifies the session's token against the auth service on
| every request. Scheduling holds no passwords — the login form relays them to
| the authority and keeps only the token it gets back.
|
*/

Route::redirect('/', '/board');

// The only routes reachable without a token. Anything added outside the group
// below is public, so add it here deliberately or not at all.
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware('auth.service')->group(function (): void {

    // Who is making the change, for local development only. A real sign-in wins:
    // ActingUser reads $request->user() first, which the middleware now populates,
    // so this picker no longer decides anything while anyone is signed in.
    Route::post('/acting-user', [BoardController::class, 'setActingUser'])->name('acting-user');

    Route::get('/board', [BoardController::class, 'index'])->name('board');
    Route::get('/board/week', [BoardController::class, 'week'])->name('board.week');

    Route::post('/board/shifts', [BoardController::class, 'storeShift'])->name('board.shifts.store');
    Route::put('/board/shifts/{shift}', [BoardController::class, 'updateShift'])->name('board.shifts.update');
    Route::post('/board/shifts/{shift}/split', [BoardController::class, 'splitShift'])->name('board.shifts.split');

    // Drag and drop targets. JSON in, JSON out — the grid posts and reloads.
    Route::post('/board/shifts/{shift}/move', [BoardController::class, 'moveShift'])->name('board.shifts.move');
    Route::post('/board/shifts/{shift}/copy', [BoardController::class, 'copyShift'])->name('board.shifts.copy');
    // Pull ACTUAL hours from TCP. The other half of the split: planned shifts go
    // out to Humanity, worked hours come in from TCP. Neither crosses over.
    Route::post('/board/pull-segments', [BoardController::class, 'pullSegments'])->name('board.pull-segments');

    // Publish the visible range to Humanity, and unlock one shift for editing.
    // Nothing reaches Humanity until the first of these is pressed.
    Route::post('/board/publish', [BoardController::class, 'publish'])->name('board.publish');
    Route::post('/board/shifts/{shift}/unpublish', [BoardController::class, 'unpublishShift'])->name('board.shifts.unpublish');

    Route::post('/board/shifts/{shift}/punch-in', [BoardController::class, 'punchIn'])->name('board.shifts.punch-in');
    Route::delete('/board/shifts/{shift}', [BoardController::class, 'destroyShift'])->name('board.shifts.destroy');

    // Actual hours, in full: create the ones nobody punched, correct them,
    // approve them one at a time, delete them. The same WorkSegmentService the
    // JSON API calls, and every write is queued on to TCP.
    Route::post('/board/segments', [BoardController::class, 'storeSegment'])->name('board.segments.store');
    Route::post('/board/segments/{segment}/punch-out', [BoardController::class, 'punchOut'])->name('board.segments.punch-out');
    Route::put('/board/segments/{segment}', [BoardController::class, 'updateSegment'])->name('board.segments.update');
    Route::post('/board/segments/{segment}/approve', [BoardController::class, 'approveSegment'])->name('board.segments.approve');
    Route::delete('/board/segments/{segment}', [BoardController::class, 'destroySegment'])->name('board.segments.destroy');

    // Filed on somebody's behalf — employees have no login here. The API takes
    // the same request from an employee-facing client; requested_by_user_id is
    // what tells the two apart.
    Route::post('/board/requests', [BoardController::class, 'storeRequest'])->name('board.requests.store');
    Route::put('/board/requests/{employeeRequest}', [BoardController::class, 'updateRequest'])->name('board.requests.update');
    Route::post('/board/requests/{employeeRequest}/decide', [BoardController::class, 'decideRequest'])->name('board.requests.decide');
    // Withdrawal appends a cancelled decision. There is no destroy to pair with
    // it: the trail is the point.
    Route::post('/board/requests/{employeeRequest}/withdraw', [BoardController::class, 'withdrawRequest'])->name('board.requests.withdraw');
    // Per-store settings. timezone is the load-bearing one — it decides which
    // calendar day every shift is filed under.
    Route::get('/board/settings', [BoardController::class, 'settings'])->name('board.settings');
    Route::put('/board/settings', [BoardController::class, 'updateSettings'])->name('board.settings.update');

    Route::post('/board/reseed', [BoardController::class, 'reseed'])->name('board.reseed');

});
