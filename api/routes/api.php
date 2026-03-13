<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TimeEntryController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/health', fn () => response()->json(['status' => 'ok']));

// Auth
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [PasswordResetController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/token', [AuthController::class, 'createToken']);
    Route::get('/auth/tokens', [AuthController::class, 'listTokens']);
    Route::delete('/auth/tokens/{token}', [AuthController::class, 'revokeToken']);

    // Organizations (no org context needed)
    Route::apiResource('organizations', OrganizationController::class);
    Route::get('/organizations/{organization}/members', [OrganizationController::class, 'members']);
    Route::post('/organizations/{organization}/members', [OrganizationController::class, 'addMember']);
    Route::delete('/organizations/{organization}/members/{user}', [OrganizationController::class, 'removeMember']);

    // All routes below require X-Organization-Id header
    Route::middleware('resolve.organization')->group(function () {
        // Clients
        Route::apiResource('clients', ClientController::class);

        // Projects
        Route::apiResource('projects', ProjectController::class);

        // Tasks
        Route::apiResource('tasks', TaskController::class)->only(['index', 'store', 'update', 'destroy']);

        // Time Entries
        Route::post('/time-entries/start', [TimeEntryController::class, 'start']);
        Route::post('/time-entries/stop', [TimeEntryController::class, 'stop']);
        Route::get('/time-entries/running', [TimeEntryController::class, 'running']);
        Route::apiResource('time-entries', TimeEntryController::class)->parameters([
            'time-entries' => 'time_entry',
        ]);

        // Tags
        Route::apiResource('tags', TagController::class)->except(['show']);

        // Users (org members)
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}', [UserController::class, 'update']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/summary', [ReportController::class, 'summary']);
            Route::get('/detailed', [ReportController::class, 'detailed']);
            Route::get('/budget', [ReportController::class, 'budget']);
            Route::get('/utilization', [ReportController::class, 'utilization']);
            Route::get('/export', [ReportController::class, 'export']);
        });
    });
});
