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
use App\Services\OrderReportService;

class OrderExportController extends Controller
{
    /**
     * Сформировать Excel по успешным заказам пакета ТТН.
     */
    public function generate(
        Request $request,
        TtnBatch $batch,
        OrderReportService $reportService
    ) {
        $createdBy = null;

        if (auth()->check()) {
            $createdBy =
                auth()->user()->email
                ?? auth()->user()->name
                ?? null;
        }

        if (!$createdBy) {
            $createdBy =
                $request->getUser();
        }

        try {
            $export =
                $reportService->generateAndSend(
                    $batch,
                    $createdBy
                );
        } catch (Throwable $e) {
            report($e);

            return back()->with(
                'error',
                'Не вдалося сформувати/відправити Excel: ' .
                $e->getMessage()
            );
        }

        return Storage::disk('local')
            ->download(
                $export->file_path,
                $export->file_name,
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