<?php

use App\Http\Controllers\OperacaoController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/operacoes', [OperacaoController::class, 'index'])->name('operacoes.index');
    Route::get('/operacoes/import-stats', [OperacaoController::class, 'importStats'])->name('operacoes.import-stats');
    Route::get('/operacoes/relatorio', [OperacaoController::class, 'report'])->name('operacoes.report');
    Route::get('/operacoes/{operacao}', [OperacaoController::class, 'show'])->name('operacoes.show');
    Route::post('/operacoes/importar', [OperacaoController::class, 'import'])->name('operacoes.import');
    Route::post('/operacoes/importar/cancelar', [OperacaoController::class, 'cancelImport'])->name('operacoes.cancel-import');
    Route::patch('/operacoes/{operacao}/status', [OperacaoController::class, 'updateStatus'])->name('operacoes.update-status');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
