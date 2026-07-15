<?php

namespace App\Http\Requests\Customer\Pledge;

use App\Enums\CustomScheduleFrequency;
use App\Enums\PledgePaymentPlanType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class UpdatePledgeRescheduleRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $schedule = $this->input('schedule');
        if (! is_array($schedule)) {
            return;
        }

        if (isset($schedule['duration']) && ! isset($schedule['interval'])) {
            $schedule['interval'] = $schedule['duration'];
        }

        if (isset($schedule['frequency']) && is_string($schedule['frequency'])) {
            $schedule['frequency'] = strtolower(trim($schedule['frequency']));
        }

        $this->merge(['schedule' => $schedule]);
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'payment_plan_type' => ['sometimes', 'nullable', 'string', Rule::in(PledgePaymentPlanType::values())],
            'installment_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'schedule' => ['sometimes', 'nullable', 'array'],
            'schedule.frequency' => [
                Rule::requiredIf(fn () => $this->requiresCustomScheduleConfig()),
                'nullable',
                'string',
                Rule::in(CustomScheduleFrequency::values()),
            ],
            'schedule.interval' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'schedule.duration' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->requiresCustomScheduleConfig() && ! $this->filled('installment_count')) {
                $validator->errors()->add(
                    'installment_count',
                    'Number of installments is required when rescheduling to a custom payment plan.',
                );
            }
        });
    }

    private function requiresCustomScheduleConfig(): bool
    {
        if ($this->input('payment_plan_type') !== PledgePaymentPlanType::CUSTOM->value) {
            return false;
        }

        return ! is_array($this->input('schedule')) || $this->input('schedule') === [];
    }
}
