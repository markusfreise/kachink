<?php

use App\Http\Controllers\Api\AsanaImportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DeviceAuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectTaskAttachmentController;
use App\Http\Controllers\Api\ProjectTaskCommentController;
use App\Http\Controllers\Api\ProjectTaskController;
use App\Http\Controllers\Api\ProjectWatcherController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskStatusController;
use App\Http\Controllers\Api\TimeEntryController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/health', fn () => response()->json(['status' => 'ok']));

// Auth
// Device sign-in for the menubar app (browser approves, app polls)
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/auth/device/start', [DeviceAuthController::class, 'start']);
    Route::get('/auth/device/{code}', [DeviceAuthController::class, 'poll']);
});

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [PasswordResetController::class, 'resetPassword']);
});

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/token', [AuthController::class, 'createToken']);
    Route::get('/auth/tokens', [AuthController::class, 'listTokens']);
    Route::delete('/auth/tokens/{token}', [AuthController::class, 'revokeToken']);
    Route::get('/auth/device/{code}/info', [DeviceAuthController::class, 'show']);
    Route::post('/auth/device/{code}/approve', [DeviceAuthController::class, 'approve']);
    Route::post('/auth/device/{code}/deny', [DeviceAuthController::class, 'deny']);

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

        // Project watchers
        Route::get('/projects/{project}/watchers', [ProjectWatcherController::class, 'index']);
        Route::put('/projects/{project}/watchers', [ProjectWatcherController::class, 'sync']);
        Route::post('/projects/{project}/watch', [ProjectWatcherController::class, 'watch']);
        Route::delete('/projects/{project}/watch', [ProjectWatcherController::class, 'unwatch']);

        // Tasks
        Route::apiResource('tasks', TaskController::class)->only(['index', 'store', 'update', 'destroy']);

        // Asana import assistant (admins)
        Route::prefix('asana')->group(function () {
            Route::get('/settings', [AsanaImportController::class, 'settings']);
            Route::put('/settings', [AsanaImportController::class, 'saveSettings']);
            Route::delete('/settings', [AsanaImportController::class, 'deleteSettings']);
            Route::get('/projects', [AsanaImportController::class, 'projects']);
            Route::put('/projects/{gid}/client', [AsanaImportController::class, 'mapClient']);
            Route::get('/projects/{gid}/tasks', [AsanaImportController::class, 'tasks']);
            Route::post('/projects/{gid}/import', [AsanaImportController::class, 'import']);
        });

        // Task statuses (free-form per organization, "Heute" is fixed)
        Route::post('/task-statuses/reorder', [TaskStatusController::class, 'reorder']);
        Route::apiResource('task-statuses', TaskStatusController::class)->only(['index', 'store', 'update', 'destroy'])->parameters([
            'task-statuses' => 'task_status',
        ]);

        // Project tasks (task management with subtasks, comments, attachments, history)
        Route::post('/project-tasks/reorder', [ProjectTaskController::class, 'reorder']);
        Route::apiResource('project-tasks', ProjectTaskController::class)->parameters([
            'project-tasks' => 'project_task',
        ]);
        Route::post('/project-tasks/{project_task}/comments', [ProjectTaskCommentController::class, 'store']);
        Route::put('/project-tasks/{project_task}/comments/{comment}', [ProjectTaskCommentController::class, 'update']);
        Route::delete('/project-tasks/{project_task}/comments/{comment}', [ProjectTaskCommentController::class, 'destroy']);
        Route::post('/project-tasks/{project_task}/attachments', [ProjectTaskAttachmentController::class, 'store']);
        Route::get('/project-tasks/{project_task}/attachments/{attachment}/download', [ProjectTaskAttachmentController::class, 'download']);
        Route::delete('/project-tasks/{project_task}/attachments/{attachment}', [ProjectTaskAttachmentController::class, 'destroy']);

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
        Route::post('/users/{user}/avatar', [UserController::class, 'uploadAvatar']);
        Route::delete('/users/{user}/avatar', [UserController::class, 'deleteAvatar']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/summary', [ReportController::class, 'summary']);
            Route::get('/detailed', [ReportController::class, 'detailed']);
            Route::get('/budget', [ReportController::class, 'budget']);
            Route::get('/utilization', [ReportController::class, 'utilization']);
            Route::get('/export', [ReportController::class, 'export']);

            // Scoped reports: ?date_from&date_to&rounding&format=json|pdf|csv&locale=de|en
            Route::get('/organization', [ReportController::class, 'organization']);
            Route::get('/clients/{client}', [ReportController::class, 'client']);
            Route::get('/projects/{project}', [ReportController::class, 'project']);
            Route::get('/users/{user}', [ReportController::class, 'user']);
        });
    });
});
