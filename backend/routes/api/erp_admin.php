<?php

use App\Http\Controllers\Api\V1\GovernanceController;
use App\Http\Controllers\Api\V1\SaasEntitlementController;
use Illuminate\Support\Facades\Route;

Route::get('/governance/summary', [GovernanceController::class, 'summary']);
Route::get('/governance/records', [GovernanceController::class, 'index']);
Route::post('/governance/records', [GovernanceController::class, 'store']);
Route::patch('/governance/records/{record}', [GovernanceController::class, 'update']);
Route::get('/saas/entitlements', [SaasEntitlementController::class, 'show']);
Route::put('/saas/entitlements', [SaasEntitlementController::class, 'update']);

Route::middleware('module:sales')->group(function () {
    Route::get('/sales-orders', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'salesOrders']);
    Route::post('/sales-orders', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'storeSalesOrder'])->middleware('idempotency');
    Route::post('/sales-orders/{id}/payments', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'allocatePayment'])->middleware('idempotency');
    Route::post('/sales-orders/{id}/{action}', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'transitionSalesOrder'])->middleware('idempotency')->where('action', 'confirm|fulfill|cancel');
    Route::post('/quotations/{id}/convert-sales-order', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'convertQuotation']);
    Route::get('/sales-returns', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'salesReturns']);
    Route::post('/sales-returns', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'storeSalesReturn']);
    Route::post('/sales-returns/{id}/{action}', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'transitionSalesReturn'])->where('action', 'submit|approve|reject|complete|cancel');
});

Route::middleware('module:procurement')->group(function () {
    Route::get('/purchase-requests', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'purchaseRequests']);
    Route::post('/purchase-requests', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'storePurchaseRequest'])->middleware('idempotency');
    Route::post('/purchase-requests/{id}/{action}', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'transitionPurchaseRequest'])->middleware('idempotency')->where('action', 'submit|approve|reject|cancel');
    Route::get('/procurement-rfqs', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'rfqs']);
    Route::post('/procurement-rfqs', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'storeRfq']);
    Route::post('/procurement-rfqs/{id}/select-vendor', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'selectRfqVendor']);
    Route::post('/procurement-rfqs/{id}/convert-purchase-order', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'convertRfq']);
});

Route::middleware('module:distribution')->group(function () {
    Route::get('/delivery-fleets', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'fleets']);
    Route::post('/delivery-fleets', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'storeFleet']);
    Route::get('/delivery-returns', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'deliveryReturns']);
    Route::post('/delivery-returns', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'storeDeliveryReturn']);
    Route::post('/delivery-returns/{id}/{action}', [\App\Http\Controllers\Api\V1\CommercialWorkflowController::class, 'transitionDeliveryReturn'])->where('action', 'review|approve|reject|complete|cancel');
});
