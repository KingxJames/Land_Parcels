<?php

use Illuminate\Support\Facades\Route;
// Scaffold route left off until LandTrackerController exists; it broke route:list.
// Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
//     Route::apiResource('landtrackers', LandTrackerController::class)->names('landtracker');
// });

// Private buyer ID details for anti-money-laundering checks: office staff only
use Modules\LandTracker\Http\Controllers\BuyerProfileController;

Route::middleware(['auth:sanctum', 'staff', 'org'])->prefix('v1/buyer-profiles')->group(function () {
    Route::get('/', [BuyerProfileController::class, 'index'])->middleware('permission:buyers.verify');
    Route::get('{user}', [BuyerProfileController::class, 'show'])->middleware('permission:buyers.verify');
    Route::post('{user}', [BuyerProfileController::class, 'save'])->middleware('permission:buyers.verify');
    Route::post('{user}/verify', [BuyerProfileController::class, 'verify'])->middleware('permission:buyers.verify');
    Route::get('{user}/id-file', [BuyerProfileController::class, 'idFile'])->middleware('permission:buyers.verify');
});

// Lands
use Modules\LandTracker\Http\Controllers\LandController;

// Public website, no sign-in
Route::prefix('v1/public')->group(function () {
    Route::get('lands', [LandController::class, 'publicIndex']);
    Route::get('lands/{land}', [LandController::class, 'publicShow']);
});
Route::get('v1/lands/{land}/site-plan', [LandController::class, 'sitePlan']); // checks access itself

// Office staff: everyone reads, changes need lands.manage
Route::middleware(['auth:sanctum', 'staff', 'org'])->prefix('v1')->group(function () {
    Route::apiResource('lands', LandController::class)->only(['index', 'show']);
    Route::apiResource('lands', LandController::class)->only(['store', 'update', 'destroy'])->middleware('permission:lands.manage');
    Route::delete('lands/{land}/site-plan', [LandController::class, 'removeSitePlan'])->middleware('permission:lands.manage');
});

// Parcels
use Modules\LandTracker\Http\Controllers\ParcelController;

Route::get('v1/public/lands/{land}/parcels', [ParcelController::class, 'publicIndex']);

Route::middleware('auth:sanctum')->get('v1/my/parcels', [ParcelController::class, 'mine']);

Route::middleware(['auth:sanctum', 'staff', 'org'])->prefix('v1')->group(function () {
    Route::get('lands/{land}/parcels', [ParcelController::class, 'index']);
    Route::post('lands/{land}/parcels', [ParcelController::class, 'store'])->middleware('permission:parcels.manage');
    Route::get('parcels/{parcel}', [ParcelController::class, 'show']);
    Route::patch('parcels/{parcel}', [ParcelController::class, 'update'])->middleware('permission:parcels.manage');
    Route::delete('parcels/{parcel}', [ParcelController::class, 'destroy'])->middleware('permission:parcels.manage');
    Route::post('parcels/{parcel}/reserve', [ParcelController::class, 'reserve'])->middleware('permission:parcels.sell');
    Route::post('parcels/{parcel}/release', [ParcelController::class, 'release'])->middleware('permission:parcels.sell');
    Route::post('parcels/{parcel}/sell', [ParcelController::class, 'sell'])->middleware('permission:parcels.sell');
});

// Parcel outlines and photos
use Modules\LandTracker\Http\Controllers\ParcelOutlineController;
use Modules\LandTracker\Http\Controllers\ParcelPhotoController;

Route::get('v1/public/parcels/{parcel}', [ParcelController::class, 'publicShow']);
Route::get('v1/parcel-photos/{photo}', [ParcelPhotoController::class, 'file']); // checks access itself

Route::middleware(['auth:sanctum', 'staff', 'org'])->prefix('v1')->group(function () {
    Route::get('parcels/{parcel}/outline', [ParcelOutlineController::class, 'show']);
    Route::put('parcels/{parcel}/outline', [ParcelOutlineController::class, 'save'])->middleware('permission:parcels.manage');
    Route::delete('parcels/{parcel}/outline', [ParcelOutlineController::class, 'destroy'])->middleware('permission:parcels.manage');

    Route::post('parcels/{parcel}/photos', [ParcelPhotoController::class, 'store'])->middleware('permission:parcels.manage');
    Route::put('parcels/{parcel}/photos/order', [ParcelPhotoController::class, 'reorder'])->middleware('permission:parcels.manage');
    Route::delete('parcel-photos/{photo}', [ParcelPhotoController::class, 'destroy'])->middleware('permission:parcels.manage');
});

// Payment plans and their instalments
use Modules\LandTracker\Http\Controllers\PaymentPlanController;

Route::middleware('auth:sanctum')->get('v1/my/parcels/{parcel}/plan', [PaymentPlanController::class, 'mine']);

Route::middleware(['auth:sanctum', 'staff', 'org'])->prefix('v1')->group(function () {
    Route::post('payment-plans/preview', [PaymentPlanController::class, 'preview'])->middleware('permission:plans.manage');
    Route::get('parcels/{parcel}/plan', [PaymentPlanController::class, 'show']);
    Route::post('parcels/{parcel}/plan', [PaymentPlanController::class, 'store'])->middleware('permission:plans.manage');
    Route::put('parcels/{parcel}/plan', [PaymentPlanController::class, 'update'])->middleware('permission:plans.manage');
    Route::delete('parcels/{parcel}/plan', [PaymentPlanController::class, 'destroy'])->middleware('permission:plans.manage');
});

// Payments
use Modules\LandTracker\Http\Controllers\PaymentController;

Route::middleware('auth:sanctum')->prefix('v1/my/parcels/{parcel}/payments')->group(function () {
    Route::get('/', [PaymentController::class, 'mine']);
    Route::post('/', [PaymentController::class, 'report'])->middleware('throttle:payment_reports');
});

Route::middleware(['auth:sanctum', 'staff', 'org'])->prefix('v1')->group(function () {
    Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payments.record|payments.confirm');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payments.record|payments.confirm');
    Route::post('payments/{payment}/confirm', [PaymentController::class, 'confirm'])->middleware('permission:payments.confirm');
    Route::post('payments/{payment}/reject', [PaymentController::class, 'reject'])->middleware('permission:payments.confirm');
    Route::get('parcels/{parcel}/payments', [PaymentController::class, 'forParcel'])->middleware('permission:payments.record|payments.confirm');
    Route::post('parcels/{parcel}/payments', [PaymentController::class, 'store'])->middleware('permission:payments.record');
});

// Receipts and reminders
use Modules\LandTracker\Http\Controllers\ReceiptController;
use Modules\LandTracker\Http\Controllers\ReminderController;

// Staff or the receipt's own buyer (checked inside)
Route::middleware('auth:sanctum')->prefix('v1/receipts/{receipt}')->group(function () {
    Route::get('/', [ReceiptController::class, 'show']);
    Route::get('print', [ReceiptController::class, 'print']);
});

Route::middleware(['auth:sanctum', 'staff', 'org'])->prefix('v1')->group(function () {
    Route::post('receipts/{receipt}/send', [ReceiptController::class, 'send'])->middleware(['permission:receipts.send', 'throttle:sending']);
    Route::get('parcels/{parcel}/reminders', [ReminderController::class, 'forParcel'])->middleware('permission:reminders.send');
    Route::post('instalments/{instalment}/remind', [ReminderController::class, 'send'])->middleware(['permission:reminders.send', 'throttle:sending']);
});

// Enquiries
use Modules\LandTracker\Http\Controllers\EnquiryController;

Route::post('v1/public/enquiries', [EnquiryController::class, 'submit'])->middleware('throttle:enquiries');

Route::middleware(['auth:sanctum', 'staff', 'org'])->prefix('v1')->group(function () {
    Route::get('enquiries', [EnquiryController::class, 'index'])->middleware('permission:enquiries.manage');
    Route::get('enquiries/{enquiry}', [EnquiryController::class, 'show'])->middleware('permission:enquiries.manage');
    Route::patch('enquiries/{enquiry}', [EnquiryController::class, 'update'])->middleware('permission:enquiries.manage');
    Route::post('enquiries/{enquiry}/reserve', [EnquiryController::class, 'reserve'])->middleware(['permission:enquiries.manage', 'permission:parcels.sell']);
});

// Documents
use Modules\LandTracker\Http\Controllers\DocumentController;

Route::get('v1/public/documents', [DocumentController::class, 'publicIndex']);
Route::get('v1/documents/{document}/download', [DocumentController::class, 'download']); // checks access itself
Route::middleware('auth:sanctum')->get('v1/my/documents', [DocumentController::class, 'mine']);

Route::middleware(['auth:sanctum', 'staff', 'org'])->prefix('v1')->group(function () {
    Route::get('documents', [DocumentController::class, 'index'])->middleware('permission:documents.manage');
    Route::post('documents', [DocumentController::class, 'store'])->middleware('permission:documents.manage');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->middleware('permission:documents.manage');
});

// Payment connections, provider notices, activity log
use Modules\LandTracker\Http\Controllers\ActivityController;
use Modules\LandTracker\Http\Controllers\PaymentConnectionController;

Route::post('v1/webhooks/payments/{connection}', [PaymentConnectionController::class, 'receive'])->middleware('throttle:payment_notices');

Route::middleware(['auth:sanctum', 'staff', 'org'])->prefix('v1')->group(function () {
    Route::get('payment-connections', [PaymentConnectionController::class, 'index'])->middleware('permission:connections.manage');
    Route::post('payment-connections', [PaymentConnectionController::class, 'store'])->middleware('permission:connections.manage');
    Route::patch('payment-connections/{connection}', [PaymentConnectionController::class, 'update'])->middleware('permission:connections.manage');
    Route::delete('payment-connections/{connection}', [PaymentConnectionController::class, 'destroy'])->middleware('permission:connections.manage');
    Route::get('payment-connections/{connection}/notices', [PaymentConnectionController::class, 'notices'])->middleware('permission:connections.manage');
    Route::get('activity', [ActivityController::class, 'index'])->middleware('permission:activity.view');
});

// Your subscription billing: platform owner only
use Modules\LandTracker\Http\Controllers\BillingController;

Route::middleware(['auth:sanctum', 'platform'])->prefix('v1/billing')->group(function () {
    Route::get('subscriptions', [BillingController::class, 'subscriptions']);
    Route::put('subscriptions/{org}', [BillingController::class, 'saveSubscription']);
    Route::get('subscriptions/{org}/preview', [BillingController::class, 'preview']);
    Route::get('invoices', [BillingController::class, 'invoices']);
    Route::post('invoices/{invoice}/paid', [BillingController::class, 'markPaid']);
});
