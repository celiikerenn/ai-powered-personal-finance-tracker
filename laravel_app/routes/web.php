<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportsController;
use Illuminate\Support\Facades\Route;

// Ana sayfa -> girişe yönlendir
Route::get('/', fn () => redirect()->route('login'));

// Misafir (giriş yapmamış)
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
Route::post('/register', [AuthController::class, 'register']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Oturum gerekli (session'da user_id kontrolü controller içinde)
Route::middleware('web')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/charts', [DashboardController::class, 'charts'])->name('charts');
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::post('/expenses/fixed-monthly', [ExpenseController::class, 'storeMonthlyFixedExpenses'])->name('expenses.fixed-monthly.store');
    Route::get('/expenses/{expenseId}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
    Route::put('/expenses/{expenseId}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::delete('/expenses/{expenseId}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('/reports/{year}/{month}.csv', [ReportsController::class, 'downloadCsv'])->name('reports.csv');
    Route::get('/reports/{year}/{month}.pdf', [ReportsController::class, 'downloadPdf'])->name('reports.pdf');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::post('/profile/currency', [ProfileController::class, 'updateCurrency'])->name('profile.currency.update');
    Route::post('/profile/update-budget', [ProfileController::class, 'updateBudget'])->name('profile.update-budget');

    Route::get('/profile/budget', [ProfileController::class, 'showBudget'])->name('profile.budget.show');

    Route::post('/profile/change-password', [ProfileController::class, 'changePassword'])
        ->name('profile.change-password.update');
    Route::post('/profile/fixed-monthly/templates', [ProfileController::class, 'updateMonthlyFixedExpenses'])
        ->name('profile.fixed-monthly.templates.update');
});
