<?php

namespace App\Http\Requests\ProjectTask;

use App\Models\ProjectTask;
use App\Rules\InOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'uuid', InOrganization::exists('projects')],
            'parent_id' => ['nullable', 'uuid', InOrganization::exists('project_tasks')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'assignee_id' => ['nullable', 'uuid', self::orgMember()],
            'priority' => ['sometimes', Rule::in(ProjectTask::PRIORITIES)],
            'status_id' => ['nullable', 'uuid', InOrganization::exists('task_statuses')],
            'estimate_minutes' => ['nullable', 'integer', 'min:0', 'max:5256000'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'deadline' => ['nullable', 'date_format:Y-m-d'],
            'reminder_at' => ['nullable', 'date_format:Y-m-d'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['uuid', InOrganization::exists('tags')],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** Assignees must be members of the current organization. */
    public static function orgMember(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $isMember = app('current_organization')->users()->where('users.id', $value)->exists();
            if (! $isMember) {
                $fail(__('validation.exists', ['attribute' => 'assignee']));
            }
        };
    }
}
