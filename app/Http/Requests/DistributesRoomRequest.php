<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DistributesRoomRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     *
     * Merge config defaults so the job always receives a complete solver_config
     * even when the modal sends only a subset of fields.
     */
    protected function prepareForValidation()
    {
        $defaults = array_merge(
            config('alocacao.room_allocation', []),
            [
                'historical_estimation_method' => config('alocacao.historical_estimation_method', 'average_plus_stddev'),
                'historical_threshold_percent' => config('alocacao.historical_threshold_percent', 7.0),
                'historical_lookback_years' => config('alocacao.historical_lookback_years', 5),
                'historical_min_years' => config('alocacao.historical_min_years', 2),
                'historical_cap' => config('alocacao.historical_cap', 100),
                'historical_stddev_multiplier' => config('alocacao.historical_stddev_multiplier', 3.0),
            ]
        );

        $this->merge([
            'solver_config' => array_merge(
                $defaults,
                $this->input('solver_config', [])
            ),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'sync_enrollment' => 'nullable|boolean',
            'use_legacy' => 'nullable|boolean',
            'compare_algorithms' => 'nullable|boolean',
            'base_allocation_state_id' => 'nullable|integer|exists:allocation_states,id',

            'rooms_id' => 'required|array',
            'rooms_id.*' => 'required|numeric',

            'solver_config' => 'nullable|array',

            'solver_config.strict_capacity' => 'nullable|boolean',
            'solver_config.block_b_restriction_for_pos' => 'nullable|boolean',
            'solver_config.block_a_restriction_for_freshmen' => 'nullable|boolean',

            'solver_config.undergrad_in_block_a_penalty' => 'nullable|numeric',
            'solver_config.pos_in_block_b_penalty' => 'nullable|numeric',
            'solver_config.waste_penalty' => 'nullable|numeric',
            'solver_config.claustrophobia_penalty' => 'nullable|numeric',
            'solver_config.comfort_zone_min_percent' => 'nullable|numeric',
            'solver_config.comfort_zone_max_percent' => 'nullable|numeric',
            'solver_config.split_class_penalty' => 'nullable|numeric',
            'solver_config.split_cohort_penalty' => 'nullable|numeric',
            'solver_config.unassigned_penalty' => 'nullable|numeric',
            'solver_config.priority_weight' => 'nullable|numeric',

            'solver_config.time_limit_seconds' => 'nullable|integer|min:1',

            'solver_config.historical_estimation_method' => 'nullable|in:average_plus_stddev,none',
            'solver_config.historical_threshold_percent' => 'nullable|numeric|min:0',
            'solver_config.historical_lookback_years' => 'nullable|integer|min:1',
            'solver_config.historical_min_years' => 'nullable|integer|min:1',
            'solver_config.historical_cap' => 'nullable|integer|min:1',
            'solver_config.historical_stddev_multiplier' => 'nullable|numeric|min:0',
        ];
    }
}
