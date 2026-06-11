<?php

declare(strict_types=1);

namespace Modules\MES\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MES\Enums\WorkCenterType;
use Modules\MES\Models\WorkCenter;

/**
 * Form Request for WorkCenter create and update operations.
 *
 * Validates all WorkCenter fields and enforces uniqueness of `code`
 * scoped to the authenticated user's company.
 */
final class WorkCenterRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<int, mixed>|string>
     */
    public function rules(): array
    {
        /** @var \Modules\Core\Models\User $user */
        $user = $this->user();
        $company_id = $user->company_id;

        /** @var WorkCenter|null $work_center */
        $work_center = $this->route('workCenter');

        $code_unique = Rule::unique('mes_work_centers', 'code')
            ->where('company_id', $company_id)
            ->ignore($work_center?->getKey());

        $is_update = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'code' => [
                $is_update ? 'sometimes' : 'required',
                'string',
                'max:32',
                $code_unique,
            ],
            'name' => [
                $is_update ? 'sometimes' : 'required',
                'string',
                'max:255',
            ],
            'type' => [
                $is_update ? 'sometimes' : 'required',
                'string',
                WorkCenterType::validationRule(),
            ],
            'capacity_per_hour' => [
                $is_update ? 'sometimes' : 'required',
                'numeric',
                'min:0',
            ],
            'capacity_uom' => [
                $is_update ? 'sometimes' : 'required',
                'string',
                'max:16',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}
