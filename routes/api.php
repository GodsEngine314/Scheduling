<?php

use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\DayCloseController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeRequestController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\WorkSegmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Scheduling API
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php under the 'api' prefix.
|
| THERE IS NO AUTHENTICATION ON ANY OF THIS. This service has no auth layer
| yet — no guard, no token middleware, no policies — so nothing below is
| protected and ApiController::actingUserId() always resolves to null, which
| is why created_by_user_id, approved_by_user_id and requested_by_user_id are
| all nullable. The ?include=cost flag that gates pay data is NOT a permission
| check either; it is a placeholder holding the shape of one. When a guard
| lands, it belongs on this file as group middleware and inside
| ApiController::wantsCost().
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

Route::get('board', [BoardController::class, 'index'])
    ->name('api.board');

Route::get('day-close', [DayCloseController::class, 'check'])
    ->name('api.day-close.check');
Route::post('day-close', [DayCloseController::class, 'store'])
    ->name('api.day-close.store');

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

Route::get('employees', [EmployeeController::class, 'index'])
    ->name('api.employees.index');
