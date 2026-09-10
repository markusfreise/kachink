<?php

namespace App\Console\Commands;

use App\Models\ProjectTask;
use App\Notifications\ProjectTaskReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Mails every assignee whose task has reached its reminder date. Runs
 * without an organization bound, so the tenant scope is off and all
 * organizations are covered in one pass.
 */
class SendProjectTaskReminders extends Command
{
    protected $signature = 'project-tasks:send-reminders';

    protected $description = 'Send reminder mails for project tasks whose reminder date has come';

    public function handle(): int
    {
        $today = CarbonImmutable::now(config('reports.timezone'))->toDateString();

        $due = ProjectTask::query()
            ->with(['assignee', 'project.client'])
            ->whereNull('completed_at')
            ->whereNull('reminder_sent_at')
            ->whereNotNull('assignee_id')
            ->whereDate('reminder_at', '<=', $today)
            ->get();

        $sent = 0;
        foreach ($due as $task) {
            if (! $task->assignee || ! $task->assignee->is_active) {
                continue;
            }
            $task->assignee->notify(new ProjectTaskReminder($task));
            $task->forceFill(['reminder_sent_at' => now()])->saveQuietly();
            $sent++;
        }

        $this->info("Reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
