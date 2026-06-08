<?php

namespace App\Support;

final class DebugSessionLogger
{
    private const SESSION_ID = '2018ae';

    public static function log(string $hypothesisId, string $location, string $message, array $data = [], string $runId = 'pre-fix'): void
    {
        // #region agent log
        $entry = json_encode([
            'sessionId' => self::SESSION_ID,
            'hypothesisId' => $hypothesisId,
            'location' => $location,
            'message' => $message,
            'data' => $data,
            'timestamp' => (int) round(microtime(true) * 1000),
            'runId' => $runId,
        ], JSON_UNESCAPED_SLASHES);

        file_put_contents(base_path('debug-2018ae.log'), $entry."\n", FILE_APPEND | LOCK_EX);
        // #endregion
    }

    public static function tokenFingerprint(string $token): string
    {
        return substr(hash('sha256', str_replace(' ', '+', trim($token))), 0, 12);
    }
}
