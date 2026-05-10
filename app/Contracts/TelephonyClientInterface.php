<?php

declare(strict_types=1);

namespace App\Contracts;

interface TelephonyClientInterface
{
    /**
     * @throws \App\Exceptions\TelephonyException
     */
    public function sendCallAssigned(int $callId, int $operatorId): void;
}
