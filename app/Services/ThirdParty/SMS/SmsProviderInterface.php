<?php

namespace App\Services\ThirdParty\SMS;

interface SmsProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function send(string $to, string $message): array;
}
