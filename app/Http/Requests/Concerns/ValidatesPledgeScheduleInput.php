<?php

namespace App\Http\Requests\Concerns;

use App\Enums\CustomScheduleFrequency;
use App\Enums\PledgePaymentPlanType;
use App\Services\Pledge\PledgeScheduleInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

trait ValidatesPledgeScheduleInput
{
    protected function preparePledgeScheduleForValidation(): void
    {
        $schedule = $this->input('schedule');
        if (! is_array($schedule) || ! PledgeScheduleInput::isConfig($schedule)) {
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

    /**
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    protected function pledgeScheduleRules(): array
    {
        return [
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
            'schedule.*.sequence' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'schedule.*.due_date' => ['sometimes', 'nullable', 'date'],
            'schedule.*.amount' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'schedule.*.pledged_amount' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
        ];
    }

    protected function appendPledgeScheduleValidation(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->requiresCustomScheduleConfig() && ! $this->filled('installment_count')) {
                $validator->errors()->add(
                    'installment_count',
                    'Number of installments is required for a custom payment schedule.',
                );
            }

            $schedule = $this->input('schedule');
            if (! is_array($schedule) || ! PledgeScheduleInput::isExplicitItems($schedule)) {
                return;
            }

            if ($this->input('payment_plan_type') !== PledgePaymentPlanType::CUSTOM->value) {
                $validator->errors()->add(
                    'schedule',
                    'Explicit installment items are only supported for custom payment plans.',
                );

                return;
            }

            $hasValidItem = false;
            foreach ($schedule as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $amount = (float) ($row['amount'] ?? $row['pledged_amount'] ?? 0);
                $dueDate = $row['due_date'] ?? null;

                if ($amount > 0 && is_string($dueDate) && trim($dueDate) !== '') {
                    $hasValidItem = true;

                    continue;
                }

                if ($amount > 0 || (is_string($dueDate) && trim($dueDate) !== '')) {
                    $validator->errors()->add(
                        "schedule.{$index}",
                        'Each installment must include both due_date and amount.',
                    );
                }
            }

            if (! $hasValidItem) {
                $validator->errors()->add(
                    'schedule',
                    'Provide at least one installment with due_date and amount.',
                );
            }
        });
    }

    protected function requiresCustomScheduleConfig(): bool
    {
        if ($this->input('payment_plan_type') !== PledgePaymentPlanType::CUSTOM->value) {
            return false;
        }

        $schedule = $this->input('schedule');
        if (! is_array($schedule) || $schedule === []) {
            return true;
        }

        return PledgeScheduleInput::isConfig($schedule);
    }
}
