<?php

namespace App\Services\Pledge;

use App\Enums\CustomScheduleFrequency;

final class PledgeScheduleInput
{
    /**
     * @param  array<string, mixed>  $schedule
     */
    public static function isExplicitItems(array $schedule): bool
    {
        if ($schedule === []) {
            return false;
        }

        if (self::isConfig($schedule)) {
            return false;
        }

        return array_is_list($schedule);
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    public static function isConfig(array $schedule): bool
    {
        if ($schedule === []) {
            return false;
        }

        if (array_is_list($schedule)) {
            return false;
        }

        return isset($schedule['frequency'])
            || isset($schedule['interval'])
            || isset($schedule['duration']);
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array{frequency: string, interval: int}
     */
    public static function normalizeConfig(array $schedule): array
    {
        $interval = $schedule['interval'] ?? $schedule['duration'] ?? 1;

        return [
            'frequency' => strtolower(trim((string) ($schedule['frequency'] ?? CustomScheduleFrequency::MONTHLY->value))),
            'interval' => max(1, (int) $interval),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{schedule: ?array<int|string, mixed>, metadata: ?array<string, mixed>}
     */
    public static function resolveStorage(array $validated): array
    {
        $metadata = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];
        $schedule = $validated['schedule'] ?? null;

        if (is_array($schedule) && self::isConfig($schedule)) {
            $metadata['schedule_config'] = self::normalizeConfig($schedule);
            $schedule = null;
        }

        return [
            'schedule' => is_array($schedule) ? $schedule : null,
            'metadata' => $metadata !== [] ? $metadata : null,
        ];
    }
}
