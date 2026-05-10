<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Call;
use App\Models\Operator;

interface OperatorAssignmentInterface
{
    /**
     * @throws \App\Exceptions\NoAvailableOperatorException
     */
    public function assign(Call $call): Operator;
}
