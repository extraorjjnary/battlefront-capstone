<?php

use App\Http\Controllers\Administration\CategoryActivationController;
use App\Http\Controllers\Administration\CategoryController;
use App\Http\Controllers\Administration\CustomerController;
use App\Http\Controllers\Administration\InventoryController;
use App\Http\Controllers\Administration\ProductActivationController;
use App\Http\Controllers\Administration\ProductController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\ProductCatalogController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
Route::resource('products', ProductCatalogController::class)
    ->only(['index', 'show'])
    ->where(['product' => '[0-9]+']);

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth', 'verified', 'can:access-administration'])
    ->prefix('administration')
    ->name('administration.')
    ->group(function () {
        Route::resource('products', ProductController::class)
            ->except(['show', 'destroy']);
        Route::patch('products/{product}/activation', ProductActivationController::class)
            ->name('products.activation.update');
        Route::post('products/{product}/inventory', [InventoryController::class, 'store'])
            ->name('products.inventory.store');

        Route::resource('categories', CategoryController::class)
            ->except(['show', 'destroy']);
        Route::patch('categories/{category}/activation', CategoryActivationController::class)
            ->name('categories.activation.update');

        Route::get('inventory', [InventoryController::class, 'index'])
            ->name('inventory.index');
        Route::patch('inventory/{inventory}', [InventoryController::class, 'update'])
            ->name('inventory.update');

        Route::resource('customers', CustomerController::class)
            ->only(['index', 'show']);
    });

require __DIR__.'/settings.php';
