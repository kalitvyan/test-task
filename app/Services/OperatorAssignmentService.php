<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\OperatorAssignmentInterface;
use App\Enums\CallStatus;
use App\Exceptions\NoAvailableOperatorException;
use App\Models\Call;
use App\Models\Client;
use App\Models\Operator;
use Illuminate\Support\Facades\DB;

/**
 * Отвечает за атомарное назначение оператора на звонок.
 * Вся бизнес-логика выбора оператора сосредоточена здесь.
 */
class OperatorAssignmentService implements OperatorAssignmentInterface
{
    /**
     * Атомарно связывает звонок с клиентом и оператором.
     *
     * @throws NoAvailableOperatorException
     */
    public function assign(Call $call): Operator
    {
        return DB::transaction(function () use ($call) {
            // Блокируем запись звонка, чтобы исключить параллельную обработку
            $call = Call::where('id', $call->id)
                ->lockForUpdate()
                ->first();

            if ($call->status !== CallStatus::New) {
                // Уже обработан другим воркером — возвращаем текущего оператора
                return $call->operator;
            }

            $this->attachClient($call);

            $operator = $this->selectOperator();

            $operator->markBusy();
            $call->assignTo($operator);

            return $operator;
        });
    }

    private function attachClient(Call $call): void
    {
        $client = Client::where('phone', $call->phone)->first();

        if ($client) {
            $call->client_id = $client->id;
        }
    }

    /**
     * Выбирает оператора с блокировкой (FOR UPDATE),
     * чтобы параллельные воркеры не захватили одного оператора.
     *
     * @throws NoAvailableOperatorException
     */
    private function selectOperator(): Operator
    {
        $operator = Operator::where('available', true)
            ->orderBy('last_call_at')
            ->lockForUpdate()
            ->first();

        if (!$operator) {
            throw new NoAvailableOperatorException('No available operators');
        }

        return $operator;
    }
}
