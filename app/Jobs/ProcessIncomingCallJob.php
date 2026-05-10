<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\OperatorAssignmentInterface;
use App\Contracts\TelephonyClientInterface;
use App\Enums\CallStatus;
use App\Exceptions\NoAvailableOperatorException;
use App\Exceptions\TelephonyException;
use App\Models\Call;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class ProcessIncomingCallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Максимум попыток для сетевых/инфраструктурных ошибок.
     * NoAvailableOperatorException обрабатывается через release() без уменьшения счётчика.
     */
    public int $tries = 5;

    public function __construct(private readonly int $callId) {}

    /**
     * Exponential backoff между попытками (секунды).
     * Защищает телефонию и БД от шквала запросов при сбое.
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(
        OperatorAssignmentInterface $assignmentService,
        TelephonyClientInterface $telephonyClient,
        LoggerInterface $logger,
    ): void {
        $call = Call::find($this->callId);

        if (!$call) {
            $logger->warning('ProcessIncomingCallJob: call not found', ['call_id' => $this->callId]);

            return;
        }

        if ($call->status !== CallStatus::New) {
            $logger->info('ProcessIncomingCallJob: call already processed, skipping', [
                'call_id' => $this->callId,
                'status'  => $call->status,
            ]);

            return;
        }

        try {
            // Атомарное назначение в транзакции с блокировками
            $operator = $assignmentService->assign($call);
        } catch (NoAvailableOperatorException) {
            // Нет операторов отложить задачу, не сжигать попытки
            $logger->warning('ProcessIncomingCallJob: no available operators, releasing', [
                'call_id' => $this->callId,
            ]);
            $this->release(30);

            return;
        }

        // Вызов телефонии вне транзакции, чтобы не держать lock на время HTTP-запроса.
        // При ошибке Job повторится: state в БД уже 'assigned', поэтому повтор телефонии
        // нужно обработать идемпотентно на стороне телефонии (callId как ключ).
        try {
            $telephonyClient->sendCallAssigned($call->id, $operator->id);
        } catch (\Throwable $e) {
            throw new TelephonyException(
                "Telephony request failed for call {$call->id}: {$e->getMessage()}",
                previous: $e,
            );
        }

        $logger->info('ProcessIncomingCallJob: call assigned', [
            'call_id'     => $call->id,
            'operator_id' => $operator->id,
            'client_id'   => $call->client_id,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        logger()->error('ProcessIncomingCallJob: permanently failed', [
            'call_id' => $this->callId,
            'error'   => $e->getMessage(),
        ]);

        // Здесь можно:
        // отправить алерт, обновить статус звонка в 'failed', уведомить команду.
    }
}
