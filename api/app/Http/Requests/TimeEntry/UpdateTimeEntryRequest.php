<?php

namespace App\Http\Requests\TimeEntry;

use App\Rules\InOrganization;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin() || $this->route('time_entry')->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'uuid', InOrganization::exists('projects')],
            'task_id' => ['nullable', 'uuid', InOrganization::exists('tasks')],
            'project_task_id' => ['nullable', 'uuid', InOrganization::exists('project_tasks')],
            'description' => ['nullable', 'string'],
            'started_at' => ['sometimes', 'date'],
            'stopped_at' => ['nullable', 'date', 'after:started_at'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'is_billable' => ['sometimes', 'boolean'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['uuid', InOrganization::exists('tags')],
        ];
    }
}
