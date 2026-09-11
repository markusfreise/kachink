<?php

namespace App\Http\Requests\TimeEntry;

use App\Rules\InOrganization;
use Illuminate\Foundation\Http\FormRequest;

class StoreTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'uuid', InOrganization::exists('projects')],
            'task_id' => ['nullable', 'uuid', InOrganization::exists('tasks')],
            'project_task_id' => ['nullable', 'uuid', InOrganization::exists('project_tasks')],
            'create_task' => ['sometimes', 'boolean'],
            'complete_task' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string'],
            'date' => ['nullable', 'date'],
            'started_at' => ['nullable', 'date'],
            'stopped_at' => ['nullable', 'date', 'after:started_at'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'is_billable' => ['sometimes', 'boolean'],
            'source' => ['sometimes', 'string'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['uuid', InOrganization::exists('tags')],
        ];
    }
}
