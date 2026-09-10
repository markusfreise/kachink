<?php

namespace App\Notifications;

use App\Models\ProjectTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectTaskReminder extends Notification
{
    use Queueable;

    public function __construct(public readonly ProjectTask $task) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $task = $this->task->loadMissing('project.client');
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');
        $url = "{$frontend}/tasks/{$task->id}";

        $mail = (new MailMessage)
            ->subject('Erinnerung: '.$task->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('du wolltest an diese Aufgabe erinnert werden:')
            ->line('**'.$task->title.'**')
            ->line('Projekt: '.$task->project?->name.($task->project?->client ? ' ('.$task->project->client->name.')' : ''));

        if ($task->deadline) {
            $mail->line('Deadline: '.$task->deadline->format('d.m.Y'));
        }

        return $mail
            ->action('Aufgabe oeffnen', $url)
            ->salutation('kachink');
    }
}
