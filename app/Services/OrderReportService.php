<?php

namespace App\Services;

use App\Exports\OrderReportExport;
use App\Models\Order;
use App\Models\OrderExport;
use App\Models\TtnBatch;
use App\Models\TtnBatchOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelWriter;
use RuntimeException;
use Throwable;

class OrderReportService
{
    /**
     * Создать Excel для completed-пакета
     * и отправить его на ORDER_REPORT_EMAIL.
     */
    public function generateAndSend(
        TtnBatch $batch,
        $createdBy = null
    ) {
        $batch->refresh();

        $recipientEmail = trim(
            (string) config(
                'services.order_reports.recipient',
                ''
            )
        );

        if (
            $recipientEmail === ''
            || !filter_var(
                $recipientEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                'У .env не вказано коректний ORDER_REPORT_EMAIL.'
            );
        }

        /*
         * Берём только успешно сформированные ТТН
         * именно этого пакета.
         */
        $orderIds = TtnBatchOrder::query()
            ->where(
                'ttn_batch_id',
                $batch->id
            )
            ->where(
                'status',
                'success'
            )
            ->pluck('order_id')
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            throw new RuntimeException(
                'У пакеті немає успішно сформованих ТТН.'
            );
        }

        $orders = Order::query()
            ->with('items')
            ->whereIn(
                'id',
                $orderIds
            )
            ->whereNotNull(
                'tracking_number'
            )
            ->where(
                'tracking_number',
                '<>',
                ''
            )
            ->orderBy('ttn_created_at')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            throw new RuntimeException(
                'Замовлення для Excel-звіту не знайдено.'
            );
        }

        /*
         * Создаём запись отчёта.
         */
        $export = OrderExport::create([
            'ttn_batch_id' =>
                $batch->id,

            'period_from' => null,
            'period_to' => null,

            'file_name' =>
                'temporary.xlsx',

            'file_path' => null,

            'recipient_email' =>
                $recipientEmail,

            'status' =>
                'pending',

            'generated_at' => null,
            'sent_at' => null,
            'error_message' => null,

            'created_by' =>
                $createdBy,
        ]);

        $fileName = sprintf(
            'rozetka-ttn-batch-%d-%s.xlsx',
            $batch->id,
            now()->format(
                'Y-m-d-His'
            )
        );

        $relativePath =
            'order-exports/' .
            $fileName;

        /*
         * ===============================================
         * ГЕНЕРАЦИЯ EXCEL
         * ===============================================
         */
        try {
            DB::transaction(
                function () use (
                    $export,
                    $orders,
                    $fileName,
                    $relativePath
                ) {
                    $export->update([
                        'file_name' =>
                            $fileName,

                        'file_path' =>
                            $relativePath,
                    ]);

                    $export
                        ->orders()
                        ->sync(
                            $orders
                                ->pluck('id')
                                ->all()
                        );
                }
            );

            Storage::disk('local')
                ->makeDirectory(
                    'order-exports'
                );

            $stored = Excel::store(
                new OrderReportExport(
                    $orders
                ),
                $relativePath,
                'local',
                ExcelWriter::XLSX
            );

            if (!$stored) {
                throw new RuntimeException(
                    'Laravel Excel не зміг зберегти файл.'
                );
            }

            $export->update([
                'status' =>
                    'generated',

                'generated_at' =>
                    now(),

                'error_message' =>
                    null,
            ]);
        } catch (Throwable $e) {
            $export->update([
                'status' =>
                    'failed',

                'error_message' =>
                    mb_substr(
                        $e->getMessage(),
                        0,
                        65000
                    ),
            ]);

            throw $e;
        }

        /*
         * ===============================================
         * EMAIL
         * ===============================================
         */
        try {
            if (
                !Storage::disk('local')
                    ->exists(
                        $relativePath
                    )
            ) {
                throw new RuntimeException(
                    'Excel-файл не знайдено після генерації.'
                );
            }

            $absolutePath =
                Storage::disk('local')
                    ->path(
                        $relativePath
                    );

            Mail::send(
                'emails.order-report',
                [
                    'batch' =>
                        $batch,

                    'export' =>
                        $export,

                    'ordersCount' =>
                        $orders->count(),
                ],
                function ($message) use (
                    $recipientEmail,
                    $absolutePath,
                    $fileName,
                    $batch
                ) {
                    $message
                        ->to(
                            $recipientEmail
                        )
                        ->subject(
                            'Excel-звіт ТТН — пакет №' .
                            $batch->id
                        )
                        ->attach(
                            $absolutePath,
                            [
                                'as' =>
                                    $fileName,

                                'mime' =>
                                    'application/vnd.' .
                                    'openxmlformats-officedocument.' .
                                    'spreadsheetml.sheet',
                            ]
                        );
                }
            );

            $export->update([
                'status' =>
                    'sent',

                'sent_at' =>
                    now(),

                'error_message' =>
                    null,
            ]);
        } catch (Throwable $e) {
            /*
             * Excel существует, но письмо не отправилось.
             */
            $export->update([
                'status' =>
                    'generated',

                'error_message' =>
                    mb_substr(
                        'Excel сформовано, але лист ' .
                        'не відправлено: ' .
                        $e->getMessage(),
                        0,
                        65000
                    ),
            ]);

            throw $e;
        }

        return $export->fresh();
    }
}