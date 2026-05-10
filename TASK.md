# Тестовое задание
Входящий звонок создаёт запись в таблице [calls], после чего в очередь Redis отправляется ProcessIncomingCallJob.
Job должен:
- найти клиента по номеру телефона;
- выбрать доступного оператора;
- назначить звонок оператору;
- отправить событие в телефонию;
- записать лог;
- при ошибке повториться.

Система работает в production под нагрузкой. Обработка звонков выполняется несколькими воркерами параллельно.

### Фрагмент кода:

```php
class ProcessIncomingCallJob implements ShouldQueue
{
    public $tries = 5;

    private $callId;

    public function __construct($callId)
    {
        $this->callId = $callId;
    }

    public function handle()
    {
        $call = Call::find($this->callId);
    
        if (!$call) {
            return;
        }

        if ($call->status === 'new') {  
            $client = Client::where('phone', $call->phone)->first();

            if ($client) {
                $call->client_id = $client->id;
            }

            $operator = Operator::where('available', true)
                ->orderBy('last_call_at')
                ->first();

            if (!$operator) {
                throw new \Exception('No available operators');
            }

            $operator->available = false;
            $operator->save();

            $call->operator_id = $operator->id;
            $call->status = 'assigned';
            $call->save();

            // HTTP-запрос во внешнюю телефонию для назначения звонка оператору.
            // Гарантии внешней системы неизвестны.
            app(TelephonyClient::class)->sendCallAssigned($call->id, $operator->id);

            Log::info('Call assigned', [
                'call_id' => $call->id,
                'operator_id' => $operator->id,
            ]);
        }
    }
}
```

### Задачи
- Найдите 7–10 проблем в решении.
- Предложите варианты исправлений.
- Разделите проблемы по критичности:
- Критические / важные / было бы хорошо сделать
- Опишите, какие тесты вы бы добавили первыми.
- Что вы бы не стали делать прямо сейчас?

Если поведение внешней системы, очереди, телефонии или legacy-кода не описано явно, укажите свои предположения. Отдельно опишите риски и опасения, которые возникают из-за этой неопределённости.
