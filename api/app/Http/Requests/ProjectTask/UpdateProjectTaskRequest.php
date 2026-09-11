<?php

namespace App\Http\Requests\ProjectTask;

use App\Models\ProjectTask;
use App\Rules\InOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['sometimes', 'nullable', 'uuid', InOrganization::exists('project_tasks')],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'assignee_id' => ['sometimes', 'nullable', 'uuid', StoreProjectTaskRequest::orgMember()],
            'priority' => ['sometimes', Rule::in(ProjectTask::PRIORITIES)],
            'status_id' => ['sometimes', 'nullable', 'uuid', InOrganization::exists('task_statuses')],
            'estimate_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:5256000'],
            'budget' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reminder_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['uuid', InOrganization::exists('tags')],
            'position' => ['sometimes', 'integer', 'min:0'],
            'completed' => ['sometimes', 'boolean'],
            'acknowledge_today' => ['sometimes', 'boolean'],
        ];
    }
}
