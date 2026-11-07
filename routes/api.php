<?php

use App\Http\Controllers\Mobile\MobileAdjustmentController;
use App\Http\Controllers\Mobile\MobileCashBoxController;
use App\Http\Controllers\Mobile\MobileCollectionController;
use App\Http\Controllers\Mobile\MobileReadingAssignmentController;
use App\Http\Controllers\Mobile\MobileReadingController;
use App\Http\Controllers\Mobile\MobileReadingDistributionController;
use App\Http\Controllers\Mobile\MobileSessionController;
use App\Http\Controllers\Mobile\MobileSplitPaymentController;
use App\Http\Controllers\Mobile\MobileSubscriberMessageController;
use App\Http\Controllers\Mobile\MobileSubscriptionController;
use App\Http\Controllers\SubscriptionMessageController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->name('mobile.')->middleware('mobile.supported')->group(function (): void {
    Route::post('/login', [MobileSessionController::class, 'store'])->name('login');

    Route::middleware('mobile.auth')->group(function (): void {
        Route::get('/me', [MobileSessionController::class, 'show'])->name('me');
        Route::post('/logout', [MobileSessionController::class, 'destroy'])->name('logout');
        Route::get('/messages/subscriptions', [MobileSubscriberMessageController::class, 'index'])->name('messages.subscriptions');
        Route::get('/subscriptions', [MobileSubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::post('/readings', [MobileReadingController::class, 'store'])->name('readings.store');
        Route::get('/readings', [MobileReadingController::class, 'index'])->name('readings.index');
        Route::get('/reading-assignments', [MobileReadingAssignmentController::class, 'index'])->name('reading-assignments.index');
        Route::post('/reading-assignments/sync', [MobileReadingAssignmentController::class, 'sync'])->middleware('throttle:60,1')->name('reading-assignments.sync');
        Route::get('/reading-distribution', [MobileReadingDistributionController::class, 'index'])->name('reading-distribution.index');
        Route::post('/reading-distribution/{readingCycle}/assign', [MobileReadingDistributionController::class, 'store'])->name('reading-distribution.store');
        Route::post('/reading-distribution/{readingCycle}/approve', [MobileReadingDistributionController::class, 'approve'])->name('reading-distribution.approve');
        Route::get('/collections/subscriptions', [MobileCollectionController::class, 'subscriptions'])->name('collections.subscriptions');
        Route::get('/collections/subscriptions/{subscription}', [MobileCollectionController::class, 'show'])->whereNumber('subscription')->name('collections.subscriptions.show');
        Route::post('/collections/subscriptions/{subscription}/adjustments', [MobileAdjustmentController::class, 'store'])->whereNumber('subscription')->middleware('throttle:60,1')->name('collections.subscriptions.adjustments');
        Route::get('/collections/subscriptions/{subscription}/transactions', [MobileCollectionController::class, 'transactions'])->whereNumber('subscription')->name('collections.subscriptions.transactions');
        Route::post('/collections/subscriptions/{subscription}/messages', [MobileSubscriberMessageController::class, 'store'])->whereNumber('subscription')->middleware('throttle:20,1')->name('collections.subscriptions.messages.store');
        Route::get('/collections/subscriptions/{subscription}/messages', [SubscriptionMessageController::class, 'index'])->whereNumber('subscription')->name('collections.subscriptions.messages.index');
        Route::get('/collections', [MobileCollectionController::class, 'index'])->name('collections.index');
        Route::get('/collections/reference-status', [MobileCollectionController::class, 'referenceStatus'])->name('collections.reference-status');
        Route::get('/cash-boxes', [MobileCashBoxController::class, 'index'])->name('cash-boxes.index');
        Route::post('/cash-boxes/sync', [MobileCashBoxController::class, 'sync'])->name('cash-boxes.sync');
        Route::post('/cash-boxes/{action}/{record}', [MobileCashBoxController::class, 'action'])->whereNumber('record')->name('cash-boxes.actions');
        Route::post('/audit-notes/{note}/reply', [MobileCashBoxController::class, 'reply'])->whereNumber('note')->name('audit-notes.reply');
        Route::post('/collections', [MobileCollectionController::class, 'store'])->name('collections.store');
        Route::post('/split-payments', [MobileSplitPaymentController::class, 'store'])->name('split-payments.store');
    });
});
