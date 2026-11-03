<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        /*
         * Основная синхронизация.
         *
         * API фильтрует по createdAt, поэтому каждый раз
         * перечитываем скользящее окно, а не только записи
         * после предыдущего запуска.
         */
        $schedule->command(
            'rozetka:sync-orders',
            [
                '--from' => now()
                    ->subDays(
                        (int) config(
                            'services.rozetka.sync_lookback_days',
                            30
                        )
                    )
                    ->startOfDay()
                    ->format('Y-m-d H:i:s'),

                '--to' => now()
                    ->endOfDay()
                    ->format('Y-m-d H:i:s'),
            ]
        )
            ->everyTenMinutes()
            ->withoutOverlapping(30)
            ->when(function () {
                return (bool) config(
                    'services.rozetka.sync_enabled',
                    false
                );
            });

        /*
         * Более глубокая контрольная сверка.
         *
         * Нужна для заказов, созданных давно,
         * но изменившихся позднее.
         */
        $schedule->command(
            'rozetka:sync-orders',
            [
                '--from' => now()
                    ->subDays(
                        (int) config(
                            'services.rozetka.sync_reconcile_days',
                            120
                        )
                    )
                    ->startOfDay()
                    ->format('Y-m-d H:i:s'),

                '--to' => now()
                    ->endOfDay()
                    ->format('Y-m-d H:i:s'),
            ]
        )
            ->dailyAt('03:15')
            ->withoutOverlapping(120)
            ->when(function () {
                return (bool) config(
                    'services.rozetka.sync_enabled',
                    false
                );
            });

        /*
         * Optional automatic TTN processing, deliberately OFF unless
         * ROZETKA_AUTO_TTN_ENABLED=true. Separate from order syncing.
         * The command itself also checks both auto-TTN and actions
         * flags and records every attempted order durably.
         */
        // Pass boolean --execute as a bare CLI flag, not as
        // ['--execute' => true]: Laravel's scheduler can serialize
        // the latter as --execute=1, which Symfony Console rejects.
        $autoTtnCommand = 'rozetka:auto-ttn --execute --limit=20';

        $canaryOrderId = (int) config(
            'services.rozetka.auto_ttn_only_order_id',
            0
        );

        if ($canaryOrderId > 0) {
            $autoTtnCommand .= ' --order-id=' . $canaryOrderId;
        }

        $schedule->command($autoTtnCommand)
            ->everyTenMinutes()
            ->withoutOverlapping(30)
            ->appendOutputTo(storage_path('logs/rozetka-auto-ttn.log'))
            ->when(function () {
                return (bool) config(
                    'services.rozetka.auto_ttn_enabled',
                    false
                ) && (bool) config(
                    'services.rozetka.actions_enabled',
                    false
                );
            });
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
