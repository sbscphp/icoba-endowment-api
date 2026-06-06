<?php

namespace App\Services\Curl;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class CurlService
{
    public static function getRequest($url, $headerParams = null)
    {
        $curl = curl_init();
        $baseHeader = [
            'Accept: application/json',
        ];

        if (is_null($headerParams)) {
            $headers = $baseHeader;
        } else {
            $headers = array_merge($baseHeader, $headerParams);
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            Log::warning('Curl GET request failed', ['url' => $url, 'error' => $error]);

            return null;
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Curl GET response was not valid JSON', [
                'url' => $url,
                'error' => json_last_error_msg(),
            ]);

            return null;
        }

        return $decoded;
    }

    public static function postRequest($url, $postdata, $headerParams = null)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata)); //Post Fields
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 200);
        curl_setopt($ch, CURLOPT_TIMEOUT, 200);

        // Header parameter
        $baseHeader = [
            'Content-Type: application/json',
        ];

        if (is_null($headerParams)) {
            $headers = $baseHeader;
        } else {
            $headers = array_merge($baseHeader, $headerParams);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $request = curl_exec($ch);

        if ($request) {
            $result = json_decode($request, true);
            return $result;
        }else{
            if(curl_error($ch)){
                return 'error:' . curl_error($ch);
            }
        }

        curl_close($ch);
    }

    public static function postRequestVansoSMS($url, $postdata, $headerParams = null)
    {
        $systemId = config('cardinalstone.vanso_system_Id');
        $password = config('cardinalstone.vanso_password');

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // timeout after 30 seconds
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 200);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata)); // Post Fields

        // Set headers
        $baseHeader = [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode("$systemId:$password")
        ];


        if (is_null($headerParams)) {
            $headers = $baseHeader;
        } else {
            $headers = array_merge($baseHeader, $headerParams);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $request = curl_exec($ch);

        if ($request) {
            $result = json_decode($request, true);
            return $result;
        }else{
            if(curl_error($ch)){
                return 'error:' . curl_error($ch);
            }
        }

        curl_close($ch);
    }

    public static function putRequest($url, $postdata, $headerParams = null)
    {
        $curl = curl_init();
        // Header parameter
        $baseHeader = [
            'Content-Type: application/json',
        ];

        if (is_null($headerParams)) {
            $headers = $baseHeader;
        } else {
            $headers = array_merge($baseHeader, $headerParams);
        }

        curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_HTTPHEADER => $headers,
        ));

        $response = curl_exec($curl);
        return json_decode($response, true);
        curl_close($curl);

    }


}


