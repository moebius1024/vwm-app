<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMutatieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'transactie_soort_id' => ['required', 'integer'],
            'case_id' => ['required', 'integer'],
            'mode' => ['sometimes', 'string'],
        ];

        $mode = $this->mode();
        if ($mode === 'delete') {
            return array_merge($rules, $this->deletePayloadRules());
        }

        if ($this->hasObjectPayload()) {
            $rules = array_merge($rules, $this->objectPayloadRules());
        } elseif ($this->hasRoleItems()) {
            $rules = array_merge($rules, $this->rolesOnlyPayloadRules());
        } else {
            $rules = array_merge($rules, $this->legacyObjectPayloadRules());
        }

        if ($mode === 'mutate') {
            $rules = array_merge($rules, $this->mutationTargetRules());
        }

        return $rules;
    }

    /**
     * @return array{transactie_soort_id:mixed,case_id:mixed}
     */
    public function base(): array
    {
        return [
            'transactie_soort_id' => $this->validated('transactie_soort_id'),
            'case_id' => $this->validated('case_id'),
        ];
    }

    public function mode(): string
    {
        return (string) $this->input('mode', 'register');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function normalizedObjects(): array
    {
        if ($this->mode() === 'delete' || $this->hasRoleItems() && ! $this->hasObjectPayload()) {
            return [];
        }

        if ($this->hasObjectPayload()) {
            $objects = $this->validated('objects', []);

            return is_array($objects) ? $objects : [];
        }

        return [[
            'client_id' => 'obj_legacy',
            'sjabloon_uri' => $this->validated('sjabloon_uri'),
            'target_class' => $this->validated('target_class'),
            'data' => $this->validated('data'),
        ]];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function mutationTarget(): ?array
    {
        if ($this->mode() !== 'mutate') {
            return null;
        }

        $target = $this->validated('target');

        return is_array($target) ? $target : null;
    }

    /**
     * @return array{delete_type:string,target:array{goic_id:int,mutatie_id:int,tb_rdf_uri:?string,sjabloon_uri:?string}}
     */
    public function deletePayload(): array
    {
        $target = $this->validated('target', []);
        $target = is_array($target) ? $target : [];

        return [
            'delete_type' => (string) $this->validated('delete_type'),
            'target' => [
                'goic_id' => (int) ($target['goic_id'] ?? 0),
                'mutatie_id' => (int) ($target['mutatie_id'] ?? 0),
                'tb_rdf_uri' => is_string($target['tb_rdf_uri'] ?? null) ? $target['tb_rdf_uri'] : null,
                'sjabloon_uri' => is_string($target['sjabloon_uri'] ?? null) ? $target['sjabloon_uri'] : null,
            ],
        ];
    }

    private function hasObjectPayload(): bool
    {
        return ! empty($this->input('objects'));
    }

    private function hasRoleItems(): bool
    {
        $roles = $this->input('roles', []);
        $items = is_array($roles) && is_array($roles['items'] ?? null) ? $roles['items'] : [];

        return count($items) > 0;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function legacyObjectPayloadRules(): array
    {
        return [
            'sjabloon_uri' => ['required', 'string'],
            'target_class' => ['required', 'string'],
            'data' => ['required', 'array'],
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function objectPayloadRules(): array
    {
        return [
            'objects' => ['required', 'array', 'min:1'],
            'objects.*.client_id' => ['required', 'string'],
            'objects.*.sjabloon_uri' => ['required', 'string'],
            'objects.*.target_class' => ['required', 'string'],
            'objects.*.attach_to_existing' => ['sometimes', 'boolean'],
            'objects.*.existing_goic_id' => ['sometimes', 'nullable', 'integer'],
            'objects.*.data' => ['required', 'array'],
            'objects.*.data_types' => ['sometimes', 'array'],
            'roles' => ['sometimes', 'array'],
            'roles.items' => ['sometimes', 'array'],
            'roles.items.*.roleType' => ['sometimes', 'string'],
            'roles.items.*.roleTbClass' => ['sometimes', 'string'],
            'roles.items.*.fromId' => ['sometimes', 'string'],
            'roles.items.*.toId' => ['sometimes', 'string'],
            'roles.items.*.fromGoicId' => ['sometimes', 'integer'],
            'roles.items.*.toGoicId' => ['sometimes', 'integer'],
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function rolesOnlyPayloadRules(): array
    {
        return [
            'roles' => ['required', 'array'],
            'roles.items' => ['required', 'array', 'min:1'],
            'roles.items.*.roleType' => ['sometimes', 'string'],
            'roles.items.*.roleTbClass' => ['sometimes', 'string'],
            'roles.items.*.fromId' => ['sometimes', 'string'],
            'roles.items.*.toId' => ['sometimes', 'string'],
            'roles.items.*.fromGoicId' => ['sometimes', 'integer'],
            'roles.items.*.toGoicId' => ['sometimes', 'integer'],
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function mutationTargetRules(): array
    {
        return [
            'target' => ['required', 'array'],
            'target.goic_id' => ['required', 'integer'],
            'target.mutatie_id' => ['required', 'integer'],
            'target.tb_rdf_uri' => ['nullable', 'string'],
            'target.sjabloon_uri' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function deletePayloadRules(): array
    {
        return [
            'delete_type' => ['required', 'string', 'in:role,toestand'],
            'target' => ['required', 'array'],
            'target.goic_id' => ['required', 'integer'],
            'target.mutatie_id' => ['required', 'integer'],
            'target.tb_rdf_uri' => ['nullable', 'string'],
            'target.sjabloon_uri' => ['nullable', 'string'],
        ];
    }
}
