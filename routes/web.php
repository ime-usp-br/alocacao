<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SchoolTermController;
use App\Http\Controllers\SchoolClassController;
use App\Http\Controllers\InstructorController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\FusionController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\CourseScheduleController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    if(Auth::check()){
        if(Auth::user()->hasRole(["Operador","Administrador"])){
            return view("instructions");
        }
    }
    return view('main');
});

Route::resource('users', UserController::class);

Route::resource('schoolterms', SchoolTermController::class);

Route::post('/schoolclasses/makeInternalInBatch', [SchoolClassController::class, 'makeInternalInBatch'])->name('schoolclasses.makeInternalInBatch');
Route::post('/schoolclasses/destroyInBatch', [SchoolClassController::class, 'destroyInBatch'])->name('schoolclasses.destroyInBatch');
Route::get('/schoolclasses/externals', [SchoolClassController::class, 'externals'])->name('schoolclasses.externals');
Route::get('/schoolclasses/search', [SchoolClassController::class, 'search'])->name('schoolclasses.search');
Route::get('/schoolclasses/import', [SchoolClassController::class, 'import'])->name('schoolclasses.import');
Route::resource('schoolclasses', SchoolClassController::class);

Route::resource('instructors', InstructorController::class);

Route::patch('/rooms/empty', [RoomController::class, 'empty'])->name('rooms.empty');
Route::get('/rooms/reservation', [RoomController::class, 'reservation'])->name('rooms.reservation');
Route::get('/rooms/makeReport', [RoomController::class, 'makeReport'])->name('rooms.makeReport');
Route::get('/rooms/downloadReport', [RoomController::class, 'downloadReport'])->name('rooms.downloadReport');
Route::patch('/rooms/{room}/allocate', [RoomController::class, 'allocate'])->name('rooms.allocate');
Route::get('/rooms/compatible', [RoomController::class, 'compatible'])->name('rooms.compatible');
Route::patch('/rooms/distributes', [RoomController::class, 'distributes'])->name('rooms.distributes');
Route::get('/rooms/dissociate/{schoolclass}', [RoomController::class, 'dissociate'])->name('rooms.dissociate');
Route::resource('rooms', RoomController::class);

Route::resource('fusions', FusionController::class);

Route::get('/monitor/getImportProcess', [MonitorController::class, 'getImportProcess']);
Route::get('/monitor/getReportProcess', [MonitorController::class, 'getReportProcess']);
Route::get('/monitor/getReservationProcess', [MonitorController::class, 'getReservationProcess']);

Route::get('/courseschedules/licnot', [CourseScheduleController::class, 'showLicNot'])->name('courseschedules.showLicNot');
Route::get('/courseschedules', [CourseScheduleController::class, 'index'])->name('courseschedules.index');
Route::get('/courseschedules/{course}', [CourseScheduleController::class, 'show'])->name('courseschedules.show');
