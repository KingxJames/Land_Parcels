<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\GoogleController;
use Modules\Auth\Http\Controllers\CompanySettingsController;
use Modules\Auth\Http\Controllers\OrganizationController;
use Modules\Auth\Http\Controllers\PeopleController;
use Modules\Auth\Http\Controllers\RoleController;

// Not signed in yet. Add ?org=<slug> when the same email may belong to several companies.
Route::get('auth/google', [GoogleController::class, 'redirect']);
Route::get('auth/google/callback', [GoogleController::class, 'callback']);

// Public site: a company's profile and logo
Route::get('v1/public/orgs/{org:slug}', [OrganizationController::class, 'publicShow']);
Route::get('v1/public/orgs/{org:slug}/logo', [OrganizationController::class, 'logo']);

// Signed in
Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [GoogleController::class, 'me']);
    Route::post('logout', [GoogleController::class, 'logout']);
});

// Company staff: their own company. Everyone reads; changes need org.manage.
Route::middleware(['auth:sanctum', 'staff'])->group(function () {
    Route::get('v1/org', [OrganizationController::class, 'mine']);
    Route::get('v1/org/settings', [CompanySettingsController::class, 'show']);
});
// Anyone in a company (staff or owners): lists for forms, e.g. payment methods to report
Route::middleware('auth:sanctum')->get('v1/org/options', [CompanySettingsController::class, 'options']);
Route::middleware(['auth:sanctum', 'permission:org.manage'])->group(function () {
    Route::patch('v1/org', [OrganizationController::class, 'updateMine']);
    Route::post('v1/org/logo', [OrganizationController::class, 'uploadLogo']);
    Route::patch('v1/org/settings', [CompanySettingsController::class, 'update']);
});

// Platform owner: every company
Route::middleware(['auth:sanctum', 'platform'])->prefix('v1/platform')->group(function () {
    Route::get('orgs', [OrganizationController::class, 'index']);
    Route::post('orgs', [OrganizationController::class, 'store']);
    Route::patch('orgs/{org}', [OrganizationController::class, 'update']);
    Route::patch('orgs/{org}/settings', [CompanySettingsController::class, 'updateFor']);
});

// Roles and people in a company: users.manage (a company's Super admin by default)
Route::middleware(['auth:sanctum', 'org', 'permission:users.manage'])->prefix('v1')->group(function () {
    Route::get('roles', [RoleController::class, 'index']);
    Route::post('roles', [RoleController::class, 'store']);
    Route::patch('roles/{role}', [RoleController::class, 'update']);
    Route::delete('roles/{role}', [RoleController::class, 'destroy']);

    Route::get('people', [PeopleController::class, 'index']);
    Route::post('people', [PeopleController::class, 'store']);
    Route::patch('people/{user}', [PeopleController::class, 'update']);
});
