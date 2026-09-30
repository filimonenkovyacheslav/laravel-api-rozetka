<?php

namespace App\Console\Commands;

use App\Services\Rozetka\RozetkaOrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SyncRozetkaOrders extends Command
{
    protected $signature = 'rozetka:sync-orders
        {--from= : Початок періоду}
        {--to= : Кінець періоду}
        {--status= : Статус Rozetka}
        {--dry-run : Не записувати зміни в БД}';

    protected $description =
        'Синхронізація Dropshipping-замовлень з Rozetka Supplier API';

    public function handle(
        RozetkaOrderService $service
    ) {
        try {
            $filters = $this->buildFilters();

            $dryRun = (bool) $this->option(
                'dry-run'
            );

            if ($dryRun) {
                $this->warn(
                    'DRY RUN: зміни в БД не записуються.'
                );
            }

            $this->info(
                'Початок синхронізації Rozetka...'
            );

            $result = $service->sync(
                $filters,
                $dryRun
            );

            $this->newLine();

            $this->table(
                [
                    'Показник',
                    'Кількість',
                ],
                [
                    [
                        'Всього в API',
                        $result['total'],
                    ],
                    [
                        'Оброблено',
                        $result['processed'],
                    ],
                    [
                        'Нових',
                        $result['created'],
                    ],
                    [
                        'Оновлено',
                        $result['updated'],
                    ],
                    [
                        'Помилок',
                        $result['failed'],
                    ],
                ]
            );

            if (!empty($result['errors'])) {
                $this->newLine();

                $this->error(
                    'Помилки синхронізації:'
                );

                foreach (
                    $result['errors'] as $error
                ) {
                    $this->line(
                        ' - ' . $error
                    );
                }
            }

            return $result['failed'] > 0
                ? 1
                : 0;
        } catch (\Throwable $e) {
            $this->error(
                $e->getMessage()
            );

            report($e);

            return 1;
        }
    }

    private function buildFilters()
    {
        $status = trim(
            (string) $this->option('status')
        );

        $allowedStatuses = [
            'new',
            'updated',
            'deleted',
            'confirmed',
            'shipping',
            'closed',
            'delivery_refused',
            'problem',
        ];

        if (
            $status !== '' &&
            !in_array(
                $status,
                $allowedStatuses,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Невідомий статус Rozetka: ' .
                $status
            );
        }

        return array_filter([
            'dateFrom' => $this->normalizeDate(
                $this->option('from'),
                false
            ),

            'dateTo' => $this->normalizeDate(
                $this->option('to'),
                true
            ),

            'status' =>
                $status !== ''
                    ? $status
                    : null,
        ]);
    }

    private function normalizeDate(
        $value,
        $endOfDay
    ) {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);

            /*
             * Если передали только YYYY-MM-DD.
             */
            if (
                preg_match(
                    '/^\d{4}-\d{2}-\d{2}$/',
                    $value
                )
            ) {
                $date = $endOfDay
                    ? $date->endOfDay()
                    : $date->startOfDay();
            }

            return $date->format(
                'Y-m-d H:i:s'
            );
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                'Некоректна дата: ' .
                $value
            );
        }
    }
}