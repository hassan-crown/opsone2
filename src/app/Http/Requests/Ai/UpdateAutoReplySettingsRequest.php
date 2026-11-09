<?php

namespace App\Http\Requests\Ai;

use App\Ai\AutoReply\AutoReplyIntent;
use App\Ai\AutoReply\AutoReplySettings;
use App\Inbox\InboxSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAutoReplySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AutoReplySettings::canManage($this->user());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'intents' => ['required', 'array:'.implode(',', AutoReplyIntent::values())],
            'handoff_note' => ['required', 'boolean'],
            'template' => ['nullable', 'array:name,language'],
            'template.name' => ['required_with:template', 'string', 'max:250'],
            'template.language' => ['required_with:template', 'string', 'max:8'],
        ];
        foreach (AutoReplyIntent::values() as $key) {
            $rules["intents.{$key}"] = ['sometimes', 'array:enabled,mode'];
            $rules["intents.{$key}.enabled"] = ["required_with:intents.{$key}", 'boolean'];
            $rules["intents.{$key}.mode"] = ["required_with:intents.{$key}", Rule::in(AutoReplySettings::MODES)];
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $template = $this->input('template');
            if (is_array($template) && is_string($template['name'] ?? null) && is_string($template['language'] ?? null)) {
                $approved = app(InboxSettings::class)->template($template['name'], $template['language']);
                if ($approved === null || $approved['params'] !== 1) {
                    $validator->errors()->add('template', __('ai.autoreply.settings.template_invalid'));
                }
            }
        }];
    }

    /**
     * @return array{intents: array<string, array{enabled: bool, mode: string}>, handoff_note: bool, template: array{name: string, language: string}|null}
     */
    public function values(): array
    {
        $intents = [];
        foreach ((array) $this->validated('intents') as $key => $row) {
            $intents[(string) $key] = ['enabled' => (bool) $row['enabled'], 'mode' => (string) $row['mode']];
        }
        $template = $this->validated('template');

        return [
            'intents' => $intents,
            'handoff_note' => (bool) $this->validated('handoff_note'),
            'template' => is_array($template) ? ['name' => (string) $template['name'], 'language' => (string) $template['language']] : null,
        ];
    }
}
