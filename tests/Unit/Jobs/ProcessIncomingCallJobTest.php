<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Contracts\OperatorAssignmentInterface;
use App\Contracts\TelephonyClientInterface;
use App\Enums\CallStatus;
use App\Exceptions\NoAvailableOperatorException;
use App\Exceptions\TelephonyException;
use App\Jobs\ProcessIncomingCallJob;
use App\Models\Call;
use App\Models\Client;
use App\Models\Operator;
use App\Services\OperatorAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Тесты упорядочены по приоритету: сначала самые критичные для production.
 */
class ProcessIncomingCallJobTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // 1. Идемпотентность (КРИТИЧНО)
    // -------------------------------------------------------------------------

    public function test_job_is_skipped_when_call_already_assigned(): void
    {
        $call = Call::factory()->create(['status' => CallStatus::Assigned, 'operator_id' => 1]);

        $assignmentService = Mockery::mock(OperatorAssignmentInterface::class);
        $assignmentService->shouldNotReceive('assign');

        $telephonyClient = Mockery::mock(TelephonyClientInterface::class);
        $telephonyClient->shouldNotReceive('sendCallAssigned');

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once(); // "already processed"

        (new ProcessIncomingCallJob($call->id))->handle($assignmentService, $telephonyClient, $logger);
    }

    public function test_job_does_nothing_when_call_not_found(): void
    {
        $assignmentService = Mockery::mock(OperatorAssignmentInterface::class);
        $assignmentService->shouldNotReceive('assign');

        $telephonyClient = Mockery::mock(TelephonyClientInterface::class);
        $telephonyClient->shouldNotReceive('sendCallAssigned');

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()->with('ProcessIncomingCallJob: call not found', Mockery::any());

        (new ProcessIncomingCallJob(99999))->handle($assignmentService, $telephonyClient, $logger);
    }

    // -------------------------------------------------------------------------
    // 2. Race condition / параллельная обработка (КРИТИЧНО)
    // Требует реальной БД и двух параллельных соединений (интеграционный тест).
    // -------------------------------------------------------------------------

    /**
     * @group integration
     * Проверяет, что при двух одновременных запусках Job с разными callId
     * каждый получает уникального оператора.
     */
    public function test_concurrent_jobs_assign_different_operators(): void
    {
        [$call1, $call2] = Call::factory()->count(2)->create(['status' => CallStatus::New]);
        Operator::factory()->count(2)->create(['available' => true]);

        $service = app(OperatorAssignmentService::class);
        $telephony = Mockery::mock(TelephonyClientInterface::class);
        $telephony->shouldReceive('sendCallAssigned')->twice();
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->twice();

        (new ProcessIncomingCallJob($call1->id))->handle($service, $telephony, $logger);
        (new ProcessIncomingCallJob($call2->id))->handle($service, $telephony, $logger);

        $this->assertNotEquals(
            $call1->fresh()->operator_id,
            $call2->fresh()->operator_id
        );
    }

    // -------------------------------------------------------------------------
    // 3. Логика retry при отсутствии операторов (ВАЖНО)
    // -------------------------------------------------------------------------

    public function test_job_is_released_when_no_operators_available(): void
    {
        $call = Call::factory()->create(['status' => CallStatus::New]);

        $assignmentService = Mockery::mock(OperatorAssignmentInterface::class);
        $assignmentService->shouldReceive('assign')->once()->andThrow(NoAvailableOperatorException::class);

        $telephonyClient = Mockery::mock(TelephonyClientInterface::class);
        $telephonyClient->shouldNotReceive('sendCallAssigned');

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();

        $job = Mockery::mock(ProcessIncomingCallJob::class . '[release]', [$call->id])
            ->makePartial();
        $job->shouldReceive('release')->once()->with(30);

        $job->handle($assignmentService, $telephonyClient, $logger);
    }

    // -------------------------------------------------------------------------
    // 4. Ошибка телефонии (ВАЖНО)
    // -------------------------------------------------------------------------

    public function test_telephony_failure_throws_typed_exception(): void
    {
        $call = Call::factory()->create(['status' => CallStatus::New]);
        $operator = Operator::factory()->create(['available' => true]);

        $assignmentService = Mockery::mock(OperatorAssignmentInterface::class);
        $assignmentService->shouldReceive('assign')->once()->andReturn($operator);

        $telephonyClient = Mockery::mock(TelephonyClientInterface::class);
        $telephonyClient->shouldReceive('sendCallAssigned')
            ->once()
            ->andThrow(new \RuntimeException('Connection timeout'));

        $logger = Mockery::mock(LoggerInterface::class);

        $this->expectException(TelephonyException::class);

        (new ProcessIncomingCallJob($call->id))->handle($assignmentService, $telephonyClient, $logger);
    }

    // -------------------------------------------------------------------------
    // 5. Успешный путь (базовая регрессия)
    // -------------------------------------------------------------------------

    public function test_successful_assignment_logs_info(): void
    {
        $call = Call::factory()->create(['status' => CallStatus::New]);
        $operator = Operator::factory()->create(['available' => true]);

        $assignmentService = Mockery::mock(OperatorAssignmentInterface::class);
        $assignmentService->shouldReceive('assign')->once()->andReturn($operator);

        $telephonyClient = Mockery::mock(TelephonyClientInterface::class);
        $telephonyClient->shouldReceive('sendCallAssigned')->once()->with($call->id, $operator->id);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('ProcessIncomingCallJob: call assigned', Mockery::any());

        (new ProcessIncomingCallJob($call->id))->handle($assignmentService, $telephonyClient, $logger);
    }

    // -------------------------------------------------------------------------
    // 6. failed() использует logger()-хелпер (DI невозможен в этом методе)
    // -------------------------------------------------------------------------

    public function test_failed_logs_error(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with(Mockery::pattern('/permanently failed/'), Mockery::any());

        $job = new ProcessIncomingCallJob(42);
        $job->failed(new \RuntimeException('something went wrong'));
    }

    // -------------------------------------------------------------------------
    // 7. OperatorAssignmentService — юнит-тесты сервиса отдельно (ВАЖНО)
    // -------------------------------------------------------------------------

    /**
     * @group integration
     * Проверяет, что last_call_at обновляется при назначении (балансировка операторов).
     */
    public function test_operator_last_call_at_is_updated_on_assignment(): void
    {
        $call = Call::factory()->create(['status' => CallStatus::New]);
        Operator::factory()->create(['available' => true, 'last_call_at' => now()->subHour()]);

        $service = app(OperatorAssignmentService::class);
        $operator = $service->assign($call);

        $this->assertEqualsWithDelta(now()->timestamp, $operator->fresh()->last_call_at->timestamp, 5);
    }

    /**
     * @group integration
     * Проверяет, что при нет операторов выбрасывается NoAvailableOperatorException.
     */
    public function test_assignment_throws_when_no_operators(): void
    {
        $call = Call::factory()->create(['status' => CallStatus::New]);

        $this->expectException(NoAvailableOperatorException::class);

        app(OperatorAssignmentService::class)->assign($call);
    }

    /**
     * @group integration
     * Клиент найден > call.client_id заполнен.
     */
    public function test_client_is_linked_when_found_by_phone(): void
    {
        $client = Client::factory()->create(['phone' => '+79991234567']);
        $call = Call::factory()->create(['status' => CallStatus::New, 'phone' => '+79991234567']);
        Operator::factory()->create(['available' => true]);

        app(OperatorAssignmentService::class)->assign($call);

        $this->assertEquals($client->id, $call->fresh()->client_id);
    }

    /**
     * @group integration
     * Клиент не найден > звонок всё равно назначается, client_id = null.
     */
    public function test_call_is_assigned_even_without_client(): void
    {
        $call = Call::factory()->create(['status' => CallStatus::New, 'phone' => '+70000000000']);
        Operator::factory()->create(['available' => true]);

        app(OperatorAssignmentService::class)->assign($call);

        $this->assertNull($call->fresh()->client_id);
        $this->assertEquals(CallStatus::Assigned, $call->fresh()->status);
    }
}
