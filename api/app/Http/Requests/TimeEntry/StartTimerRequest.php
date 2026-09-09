<?php

namespace App\Http\Requests\TimeEntry;

use App\Rules\InOrganization;
use Illuminate\Foundation\Http\FormRequest;

class StartTimerRequest extends FormRequest
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
            'description' => ['nullable', 'string'],
            'is_billable' => ['sometimes', 'boolean'],
            'source' => ['sometimes', 'string', 'in:web,menubar,manual,api,harvest'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['uuid', InOrganization::exists('tags')],
        ];
    }
}
