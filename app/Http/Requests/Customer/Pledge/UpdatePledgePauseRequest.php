<?php

namespace App\Http\Requests\Customer\Pledge;

use App\Http\Requests\ApiFormRequest;
use App\Models\Pledge;
use App\Services\Pledge\PledgeScheduleService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdatePledgePauseRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['pause', 'resume'])],
            'resume_date' => ['required_if:action,pause', 'date', 'after:today'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('action') !== 'pause') {
                return;
            }

            $resumeDate = $this->input('resume_date');
            if (! is_string($resumeDate) || $resumeDate === '') {
                return;
            }

            $pledgeUuid = $this->route('pledgeUuid');
            if (! is_string($pledgeUuid) || $pledgeUuid === '') {
                return;
            }

            $pledge = Pledge::query()->where('uuid', $pledgeUuid)->first();
            if ($pledge === null) {
                return;
            }

            try {
                app(PledgeScheduleService::class)->assertResumeDateWithinLimit($pledge, $resumeDate);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }
}
