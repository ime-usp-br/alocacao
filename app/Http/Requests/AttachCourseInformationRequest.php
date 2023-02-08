<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachCourseInformationRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            'schoolclasses' => 'required|array',
            'schoolclasses.*' => 'required|integer',
            'numsemidl' => 'required|integer',
            'nomcur' => 'required',
            'perhab' => 'required',
            'tipobg' => 'required',
            'codhab' => 'required'
        ];

        return $rules;
    }
}
