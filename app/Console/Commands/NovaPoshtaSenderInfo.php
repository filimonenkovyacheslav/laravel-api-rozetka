<?php

namespace App\Console\Commands;

use App\Services\NovaPoshta\NovaPoshtaClient;
use Illuminate\Console\Command;
use Throwable;

class NovaPoshtaSenderInfo extends Command
{
    protected $signature = 'nova-poshta:sender-info';

    protected $description =
        'Показати відправників, контакти та адреси Нової Пошти';

    public function handle(NovaPoshtaClient $client)
    {
        try {
            $senders = $client->call(
                'Counterparty',
                'getCounterparties',
                [
                    'CounterpartyProperty' => 'Sender',
                    'Page' => '1',
                ]
            );

            if (empty($senders)) {
                $this->error(
                    'API не повернув жодного відправника.'
                );

                return 1;
            }

            foreach ($senders as $sender) {
                $this->newLine();
                $this->info('=================================');
                $this->info('ВІДПРАВНИК');
                $this->info('=================================');

                $this->line(
                    $this->prettyJson($sender)
                );

                $senderRef = isset($sender['Ref'])
                    ? $sender['Ref']
                    : null;

                if (!$senderRef) {
                    continue;
                }

                $contacts = $client->call(
                    'Counterparty',
                    'getCounterpartyContactPersons',
                    [
                        'Ref' => $senderRef,
                        'Page' => '1',
                    ]
                );

                $this->newLine();
                $this->info('КОНТАКТНІ ОСОБИ');

                $this->line(
                    $this->prettyJson($contacts)
                );

                $addresses = $client->call(
                    'Counterparty',
                    'getCounterpartyAddresses',
                    [
                        'Ref' => $senderRef,
                        'CounterpartyProperty' => 'Sender',
                    ]
                );

                $this->newLine();
                $this->info('АДРЕСИ ВІДПРАВНИКА');

                $this->line(
                    $this->prettyJson($addresses)
                );
            }

            return 0;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }

    private function prettyJson($value)
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }
}