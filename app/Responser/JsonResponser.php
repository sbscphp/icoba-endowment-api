<?php

namespace App\Responser;

use App\Models\ErrorLog;
use Illuminate\Http\JsonResponse;


class JsonResponser
{

    /**
     * Return a new JSON response with paginated data
     *
     * @param int $status
     * @param StaffStrength\ApiMgt\Http\Collections\ApiPaginatedCollection $data
     * @param string|null $message
     * @return Illuminate\Http\JsonResponse
     */
    public static function sendPaginated(
        int $status,
        $data = [],
        string $message = ""
    ): JsonResponse {
        $data = $data->toArray();
        $response = [
            'status' => $status,
            'data' => $data['data'],
            'meta' => $data['meta'],
            "message" => ucwords($message),
        ];
        return response()->json($response, $status);
    }

    /**
     * Return a new JSON response with paginated data
     *
     * @param int $status
     * @param Array $data
     * @param string|null $message
     * @return Illuminate\Http\JsonResponse
     */
    public static function send(
        bool $error = true,
        string $message = "",
        $data = [],
        $statusCode = 200,
        $th = null
    ): JsonResponse {
        if($th && $statusCode == 500){
            // ErrorLog::create([
            //     'causer' => optional(auth()->user())->id ?? 'Guest',
            //     'model' => get_class($th),
            //     'error_message' => $th->getMessage(),
            //     'error_line' => $th->getLine(),
            //     'error_trace' => $th->getTraceAsString(),
            // ]);
        }
        return response()->json([
            "error" => $error,
            "message" => $message,
            "data" => $data,
        ], $statusCode);
    }
}
