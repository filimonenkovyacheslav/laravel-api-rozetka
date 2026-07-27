<?php

namespace App\Http\Controllers\Admin;

use App\Exports\OrderReportExport;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderExport;
use App\Models\TtnBatch;
use App\Models\TtnBatchOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Throwable;
use Illuminate\Support\Facades\Mail;

class OrderExportController extends Controller
{
    /**
     * Сформировать Excel по успешным заказам пакета ТТН.
     */
    public function generate(
        Request $request,
        TtnBatch $batch
    ) {
        /*
         * Получатель берётся только из ORDER_REPORT_EMAIL.
         * Поле формы recipient_email больше не используется.
         */
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
            return back()->with(
                'error',
                'У файлі .env не вказано коректний ' .
                'ORDER_REPORT_EMAIL.'
            );
        }

        /*
         * Получаем успешно обработанные заказы
         * текущего пакета ТТН.
         */
        $orderIds = TtnBatchOrder::query()
            ->where('ttn_batch_id', $batch->id)
            ->where('status', 'success')
            ->pluck('order_id')
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            return back()->with(
                'error',
                'У цьому пакеті немає успішно сформованих ТТН.'
            );
        }

        /*
         * Загружаем заказы вместе с товарами.
         */
        $orders = Order::query()
            ->with('items')
            ->whereIn('id', $orderIds)
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '<>', '')
            ->orderBy('ttn_created_at')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            return back()->with(
                'error',
                'Замовлення з успішно сформованими ТТН ' .
                'не знайдено.'
            );
        }

        /*
         * Определяем пользователя, создавшего отчёт.
         */
        $createdBy = null;

        if (auth()->check()) {
            $createdBy =
                auth()->user()->email
                ?? auth()->user()->name
                ?? null;
        }

        if (!$createdBy) {
            $createdBy = $request->getUser();
        }

        /*
         * Создаём запись отчёта.
         */
        $export = OrderExport::create([
            'ttn_batch_id' => $batch->id,

            'period_from' => null,
            'period_to' => null,

            'file_name' => 'temporary.xlsx',
            'file_path' => null,

            'recipient_email' => $recipientEmail,

            'status' => 'pending',
            'generated_at' => null,
            'sent_at' => null,
            'error_message' => null,

            'created_by' => $createdBy,
        ]);

        $fileName = sprintf(
            'rozetka-ttn-batch-%d-%s.xlsx',
            $batch->id,
            now()->format('Y-m-d-His')
        );

        $relativePath = 'order-exports/' . $fileName;

        /*
         * =========================================================
         * ГЕНЕРАЦИЯ EXCEL
         * =========================================================
         */
        try {
            DB::transaction(function () use (
                $export,
                $orders,
                $fileName,
                $relativePath
            ) {
                $export->update([
                    'file_name' => $fileName,
                    'file_path' => $relativePath,
                ]);

                $export->orders()->sync(
                    $orders->pluck('id')->all()
                );
            });

            Storage::disk('local')->makeDirectory(
                'order-exports'
            );

            $stored = Excel::store(
                new OrderReportExport($orders),
                $relativePath,
                'local',
                ExcelWriter::XLSX
            );

            if (!$stored) {
                throw new \RuntimeException(
                    'Laravel Excel не зміг зберегти файл.'
                );
            }

            $export->update([
                'status' => 'generated',
                'generated_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $export->update([
                'status' => 'failed',

                'error_message' => mb_substr(
                    $e->getMessage(),
                    0,
                    65000
                ),
            ]);

            report($e);

            return back()->with(
                'error',
                'Не вдалося сформувати Excel: ' .
                $e->getMessage()
            );
        }

        /*
         * =========================================================
         * ОТПРАВКА НА EMAIL
         * =========================================================
         */
        try {
            if (
                !Storage::disk('local')->exists(
                    $relativePath
                )
            ) {
                throw new \RuntimeException(
                    'Сформований Excel-файл не знайдено.'
                );
            }

            $absolutePath = Storage::disk('local')->path(
                $relativePath
            );

            Mail::send(
                'emails.order-report',
                [
                    'batch' => $batch,
                    'export' => $export,
                    'ordersCount' => $orders->count(),
                ],
                function ($message) use (
                    $recipientEmail,
                    $absolutePath,
                    $fileName,
                    $batch
                ) {
                    $message
                        ->to($recipientEmail)
                        ->subject(
                            'Excel-звіт ТТН — пакет №' .
                            $batch->id
                        )
                        ->attach(
                            $absolutePath,
                            [
                                'as' => $fileName,

                                'mime' =>
                                    'application/vnd.' .
                                    'openxmlformats-officedocument.' .
                                    'spreadsheetml.sheet',
                            ]
                        );
                }
            );

            /*
             * Письмо отправлено успешно.
             */
            $export->update([
                'status' => 'sent',
                'sent_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            /*
             * Excel уже создан, поэтому статус failed
             * не устанавливаем. Его можно скачать вручную.
             */
            $export->update([
                'status' => 'generated',

                'error_message' => mb_substr(
                    'Excel сформовано, але лист не відправлено: ' .
                    $e->getMessage(),
                    0,
                    65000
                ),
            ]);

            report($e);

            return redirect()
                ->route(
                    'admin.ttn-batches.show',
                    $batch
                )
                ->with(
                    'error',
                    'Excel сформовано, але не вдалося ' .
                    'відправити його на ' .
                    $recipientEmail .
                    ': ' .
                    $e->getMessage()
                );
        }

        /*
         * После отправки также скачиваем Excel в браузер.
         */
        return Storage::disk('local')->download(
            $relativePath,
            $fileName,
            [
                'Content-Type' =>
                    'application/vnd.openxmlformats-officedocument.' .
                    'spreadsheetml.sheet',
            ]
        );
    }

    /**
     * Повторно скачать уже сформированный отчёт.
     */
    public function download(OrderExport $export)
    {
        if ($export->status !== 'generated' && $export->status !== 'sent') {
            return back()->with(
                'error',
                'Цей Excel-звіт ще не сформований.'
            );
        }

        if (!$export->file_path) {
            return back()->with(
                'error',
                'Для звіту не збережено шлях до файлу.'
            );
        }

        if (!Storage::disk('local')->exists($export->file_path)) {
            return back()->with(
                'error',
                'Excel-файл не знайдено на сервері.'
            );
        }

        return Storage::disk('local')->download(
            $export->file_path,
            $export->file_name,
            [
                'Content-Type' =>
                    'application/vnd.openxmlformats-officedocument.' .
                    'spreadsheetml.sheet',
            ]
        );
    }

    public function destroy(
        TtnBatch $batch,
        OrderExport $export
    ) {
        /*
         * Защита от удаления отчёта,
         * принадлежащего другому пакету.
         */
        if (
            (int) $export->ttn_batch_id
            !== (int) $batch->id
        ) {
            abort(404);
        }

        $filePath = trim(
            (string) $export->file_path
        );

        $fileName = trim(
            (string) $export->file_name
        );

        try {
            /*
             * Сначала удаляем физический файл.
             *
             * Если файла уже нет, всё равно удаляем
             * запись отчёта из базы.
             */
            if (
                $filePath !== ''
                && Storage::disk('local')->exists($filePath)
            ) {
                $deleted = Storage::disk('local')->delete(
                    $filePath
                );

                if (!$deleted) {
                    return back()->with(
                        'error',
                        'Не вдалося видалити Excel-файл зі сховища.'
                    );
                }
            }

            DB::transaction(function () use ($export) {
                /*
                 * Удаляем записи из связующей таблицы
                 * order_export_order.
                 */
                $export->orders()->detach();

                /*
                 * Удаляем сам отчёт.
                 */
                $export->delete();
            });

            return redirect()
                ->route(
                    'admin.ttn-batches.show',
                    $batch
                )
                ->with(
                    'success',
                    $fileName !== ''
                        ? 'Excel-звіт "' .
                            $fileName .
                            '" видалено.'
                        : 'Excel-звіт видалено.'
                );
        } catch (Throwable $e) {
            report($e);

            return back()->with(
                'error',
                'Не вдалося видалити Excel-звіт: ' .
                $e->getMessage()
            );
        }
    }
}