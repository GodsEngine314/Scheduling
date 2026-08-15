<?php

use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeRequestController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\StoreSettingController;
use App\Http\Controllers\Api\WorkSegmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Scheduling API
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php under the 'api' prefix.
|
| EVERY ROUTE BELOW REQUIRES A TOKEN the auth service issued, presented as
| `Authorization: Bearer ...`. There is no session fallback on this surface —
| see bootstrap/app.php for why a cookie must not authenticate an API write.
|
| The auth service answers BOTH questions on each request: who this is, and
| whether they may do this method on this path (rules are filed upstream under
| our service name). So there is no permission map here to drift out of step
| with the one that decides.
|
| created_by_user_id and friends stay NULLABLE even so, for a reason that is not
| laziness: a token can be valid before the users PROJECTION has caught up with
| the auth.v1.user.created event, and those columns are foreign keys into a row
| that genuinely is not there yet.
|
| Two conventions worth knowing before reading the table:
|
|   GET endpoints take store/date as QUERY parameters (?store=&date=);
|   write endpoints take them in the BODY under their column names
|   (store_id, business_date). Reads are links you can paste; writes are
|   records.
|
|   An end time before a start time is not an error. It is how a caller says
|   the block crosses midnight, and it is accepted — see
|   Api\Concerns\ResolvesLocalWindow.
|
*/

Route::middleware('auth.service')->group(function (): void {
    Route::get('board', [BoardController::class, 'index'])
        ->name('api.board');

    Route::get('shifts', [ShiftController::class, 'index'])
        ->name('api.shifts.index');
    Route::post('shifts', [ShiftController::class, 'store'])
        ->name('api.shifts.store');
    Route::put('shifts/{shift}', [ShiftController::class, 'update'])
        ->whereNumber('shift')
        ->name('api.shifts.update');
    Route::delete('shifts/{shift}', [ShiftController::class, 'destroy'])
        ->whereNumber('shift')
        ->name('api.shifts.destroy');
    // Declared ahead of the {shift} routes so 'publish' can never be read as an id.
    Route::post('shifts/publish', [ShiftController::class, 'publish'])->name('api.shifts.publish');
    Route::post('shifts/{shift}/unpublish', [ShiftController::class, 'unpublish'])->name('api.shifts.unpublish');

    Route::post('shifts/{shift}/split', [ShiftController::class, 'split'])
        ->whereNumber('shift')
        ->name('api.shifts.split');
    Route::get('shifts/{shift}/conflicts', [ShiftController::class, 'conflicts'])
        ->whereNumber('shift')
        ->name('api.shifts.conflicts');

    Route::get('work-segments', [WorkSegmentController::class, 'index'])
        ->name('api.work-segments.index');
    Route::post('work-segments', [WorkSegmentController::class, 'store'])
        ->name('api.work-segments.store');
    // Per-segment approval. There is no bulk approve by design: each employee's
    // hours are signed off individually.
    Route::post('work-segments/{workSegment}/approve', [WorkSegmentController::class, 'approve'])
        ->name('api.work-segments.approve');

    Route::put('work-segments/{workSegment}', [WorkSegmentController::class, 'update'])
        ->whereNumber('workSegment')
        ->name('api.work-segments.update');
    Route::delete('work-segments/{workSegment}', [WorkSegmentController::class, 'destroy'])
        ->whereNumber('workSegment')
        ->name('api.work-segments.destroy');

    Route::get('employee-requests', [EmployeeRequestController::class, 'index'])
        ->name('api.employee-requests.index');
    Route::post('employee-requests', [EmployeeRequestController::class, 'store'])
        ->name('api.employee-requests.store');
    Route::post('employee-requests/{employeeRequest}/decide', [EmployeeRequestController::class, 'decide'])
        ->whereNumber('employeeRequest')
        ->name('api.employee-requests.decide');
    // A correction, and the only true edit on this resource — pending only.
    Route::put('employee-requests/{employeeRequest}', [EmployeeRequestController::class, 'update'])
        ->whereNumber('employeeRequest')
        ->name('api.employee-requests.update');
    // Withdrawal is a CANCELLED decision, not a DELETE, so the trail survives.
    // There is deliberately no destroy route to pair with this.
    Route::post('employee-requests/{employeeRequest}/withdraw', [EmployeeRequestController::class, 'withdraw'])
        ->whereNumber('employeeRequest')
        ->name('api.employee-requests.withdraw');

    Route::get('employees', [EmployeeController::class, 'index'])
        ->name('api.employees.index');

    // Read only: positions is a projection of hiring's payloads, so a write
    // here would be erased by the next replay. It exists because position_id is
    // needed on every shift write and a client had no way to discover one.
    Route::get('positions', [PositionController::class, 'index'])
        ->name('api.positions.index');

    // One row per store at most, so read and write are both addressed by store
    // id. A store never configured still answers with the defaults every reader
    // falls back to; the PUT creates the row on first write. No DELETE — see
    // StoreSettingController.
    Route::get('stores/{store}/settings', [StoreSettingController::class, 'show'])
        ->whereNumber('store')
        ->name('api.stores.settings.show');
    Route::put('stores/{store}/settings', [StoreSettingController::class, 'update'])
        ->whereNumber('store')
        ->name('api.stores.settings.update');
});
