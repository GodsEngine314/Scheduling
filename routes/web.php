<?php

use App\Http\Controllers\BoardController;
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
| No auth: there is no auth layer in this service yet. Do not expose it.
|
*/

Route::redirect('/', '/board');

Route::get('/board', [BoardController::class, 'index'])->name('board');

Route::post('/board/shifts', [BoardController::class, 'storeShift'])->name('board.shifts.store');
Route::put('/board/shifts/{shift}', [BoardController::class, 'updateShift'])->name('board.shifts.update');
Route::post('/board/shifts/{shift}/split', [BoardController::class, 'splitShift'])->name('board.shifts.split');
Route::post('/board/shifts/{shift}/punch-in', [BoardController::class, 'punchIn'])->name('board.shifts.punch-in');
Route::delete('/board/shifts/{shift}', [BoardController::class, 'destroyShift'])->name('board.shifts.destroy');

Route::post('/board/segments/{segment}/punch-out', [BoardController::class, 'punchOut'])->name('board.segments.punch-out');
Route::put('/board/segments/{segment}', [BoardController::class, 'updateSegment'])->name('board.segments.update');
Route::post('/board/segments/{segment}/approve', [BoardController::class, 'approveSegment'])->name('board.segments.approve');
Route::delete('/board/segments/{segment}', [BoardController::class, 'destroySegment'])->name('board.segments.destroy');
Route::post('/board/segments/approve-all', [BoardController::class, 'approveAll'])->name('board.segments.approve-all');

Route::post('/board/day-close', [BoardController::class, 'closeDay'])->name('board.day-close');
Route::post('/board/requests/{employeeRequest}/decide', [BoardController::class, 'decideRequest'])->name('board.requests.decide');
Route::post('/board/reseed', [BoardController::class, 'reseed'])->name('board.reseed');
