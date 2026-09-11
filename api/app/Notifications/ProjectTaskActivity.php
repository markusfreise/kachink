<?php

namespace App\Notifications;

use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Mail to watchers, assignees and mentioned members about task activity. */
class ProjectTaskActivity extends Notification
{
    use Queueable;

    public function __construct(
        public readonly ProjectTask $task,
        public readonly string $event,
        public readonly User $actor,
        public readonly ?string $text = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $task = $this->task->loadMissing('project.client');
        $project = $task->project?->name ?? '';
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');
        $line = match ($this->event) {
            'created' => 'hat eine neue Aufgabe angelegt.',
            'completed' => 'hat die Aufgabe als erledigt markiert.',
            'reopened' => 'hat die Aufgabe wieder geoeffnet.',
            'assigned' => 'hat dir die Aufgabe zugewiesen.',
            'commented' => 'hat einen Kommentar geschrieben.',
            'mentioned' => 'hat dich erwaehnt.',
            default => 'hat die Aufgabe geaendert.',
        };

        $mail = (new MailMessage)
            ->subject("[{$project}] {$task->title}")
            ->greeting('Hallo '.$notifiable->name.',')
            ->line("{$this->actor->name} {$line}")
            ->line('Aufgabe: **'.$task->title.'**')
            ->line('Projekt: '.$project.($task->project?->client ? ' ('.$task->project->client->name.')' : ''));

        if ($this->text) {
            $mail->line('"'.mb_substr($this->text, 0, 600).(mb_strlen($this->text) > 600 ? '...' : '').'"');
        }

        return $mail->action('Aufgabe oeffnen', "{$frontend}/tasks/{$task->id}")->salutation('kachink');
    }
}
