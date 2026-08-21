<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use ZipArchive;

class OrderFileDownloadController extends Controller
{
    public function bulkDownload(Request $request)
    {
        $data = $request->validate([
            'order_ids' => [
                'required',
                'array',
                'min:1',
                'max:500',
            ],
            'order_ids.*' => [
                'required',
                'integer',
                'exists:orders,id',
            ],
            'mode' => [
                'required',
                'in:new,all',
            ],
        ], [
            'order_ids.required' =>
                'Оберіть хоча б одне замовлення.',
        ]);

        if (!class_exists(ZipArchive::class)) {
            return back()->withErrors([
                'На сервері не підключено PHP-розширення ZIP.',
            ]);
        }

        $orders = Order::query()
            ->whereIn('id', $data['order_ids'])
            ->get([
                'id',
                'guid',
                'comment',
            ]);

        $ordersByGuid = $orders->keyBy('guid');

        $filesQuery = OrderFile::query()
            ->whereIn('guid', $orders->pluck('guid'))
            ->orderBy('guid')
            ->orderBy('id');

        /*
         * new — только файлы, которые ещё не попадали в ZIP.
         * all — все файлы выбранных заказов.
         */
        if ($data['mode'] === 'new') {
            $filesQuery->whereNull('downloaded_at');
        }

        $files = $filesQuery->get();

        if ($files->isEmpty()) {
            $message = $data['mode'] === 'new'
                ? 'У вибраних замовленнях немає нових файлів.'
                : 'У вибраних замовленнях немає файлів.';

            return back()->withErrors([
                $message,
            ]);
        }

        $tempDirectory = storage_path('app/tmp');

        if (
            !is_dir($tempDirectory) &&
            !mkdir($tempDirectory, 0775, true) &&
            !is_dir($tempDirectory)
        ) {
            return back()->withErrors([
                'Не вдалося створити тимчасову папку для ZIP.',
            ]);
        }

        $zipFileName =
            'rozetka-files-' .
            now()->format('Y-m-d-His') .
            '.zip';

        $zipPath = $tempDirectory . DIRECTORY_SEPARATOR . $zipFileName;

        $zip = new ZipArchive();

        $openResult = $zip->open(
            $zipPath,
            ZipArchive::CREATE | ZipArchive::OVERWRITE
        );

        if ($openResult !== true) {
            return back()->withErrors([
                'Не вдалося створити ZIP-архів. Код: ' .
                $openResult,
            ]);
        }

        $addedFileIds = [];
        $errors = [];

        try {
            foreach ($files as $file) {
                $order = $ordersByGuid->get($file->guid);

                if (!$order) {
                    $errors[] =
                        'Файл ID ' .
                        $file->id .
                        ': замовлення не знайдено.';

                    continue;
                }

                $physicalPath = $this->resolvePhysicalPath($file);

                if (!$physicalPath) {
                    $errors[] =
                        'Замовлення ' .
                        $this->getOrderLabel($order) .
                        ', файл ID ' .
                        $file->id .
                        ': фізичний файл не знайдено.';

                    continue;
                }

                $orderFolder = $this->sanitizeName(
                    'order-' .
                    $order->id .
                    '-' .
                    $this->getOrderLabel($order)
                );

                $originalName = $this->resolveFileName(
                    $file,
                    $physicalPath
                );

                /*
                 * ID в имени защищает от совпадения имён.
                 */
                $archivePath =
                    $orderFolder .
                    '/' .
                    $file->id .
                    '_' .
                    $this->sanitizeName($originalName);

                $added = $zip->addFile(
                    $physicalPath,
                    $archivePath
                );

                if (!$added) {
                    $errors[] =
                        'Не вдалося додати до ZIP: ' .
                        $archivePath;

                    continue;
                }

                $addedFileIds[] = (int) $file->id;
            }

            if (!empty($errors)) {
                $zip->addFromString(
                    '_errors.txt',
                    implode(PHP_EOL, $errors)
                );
            }

            $zip->close();
        } catch (Throwable $exception) {
            $zip->close();

            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }

            report($exception);

            return back()->withErrors([
                'Помилка формування архіву: ' .
                $exception->getMessage(),
            ]);
        }

        if (empty($addedFileIds)) {
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }

            return back()->withErrors([
                'Жоден фізичний файл не вдалося знайти.',
            ]);
        }

        /*
         * Отмечаем только реально добавленные в ZIP файлы.
         */
        $downloadedBy =
            $request->server('PHP_AUTH_USER')
            ?: $request->server('REMOTE_USER')
            ?: 'admin';

        DB::table('order_files')
            ->whereIn('id', $addedFileIds)
            ->update([
                'downloaded_at' => now(),
                'downloaded_by' => $downloadedBy,
                'download_count' => DB::raw(
                    'download_count + 1'
                ),
            ]);

        return response()
            ->download(
                $zipPath,
                $zipFileName,
                [
                    'Content-Type' => 'application/zip',
                ]
            )
            ->deleteFileAfterSend(true);
    }

    /**
     * Ищет реальный путь к файлу.
     *
     */
    private function resolvePhysicalPath(OrderFile $file)
    {
        $storedPath = trim((string) $file->path);

        if (
            $storedPath === '' ||
            strlen($storedPath) > 4096 ||
            strpos($storedPath, "\0") !== false
        ) {
            return null;
        }

        /*
         * Сначала проверяем абсолютный путь в том виде,
         * в котором он хранится в базе.
         */
        if (is_file($storedPath) && is_readable($storedPath)) {
            return $storedPath;
        }

        $normalizedPath = str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            $storedPath
        );

        $relativePath = ltrim(
            $normalizedPath,
            DIRECTORY_SEPARATOR
        );

        $possiblePaths = [
            storage_path('app/' . $relativePath),
            storage_path($relativePath),
            storage_path('app/public/' . $relativePath),
            public_path($relativePath),
            base_path($relativePath),
        ];

        foreach ($possiblePaths as $possiblePath) {
            if (
                is_file($possiblePath) &&
                is_readable($possiblePath)
            ) {
                return $possiblePath;
            }
        }

        return null;
    }

    private function resolveFileName(
        OrderFile $file,
        $physicalPath
    ) {
        $fileName = basename(
            str_replace('\\', '/', (string) $file->path)
        );

        if ($fileName !== '' && $fileName !== '.') {
            return $fileName;
        }

        return basename($physicalPath);
    }

    private function getOrderLabel(Order $order)
    {
        if (trim((string) $order->comment) !== '') {
            return trim((string) $order->comment);
        }

        return (string) $order->id;
    }

    private function sanitizeName($name)
    {
        $name = trim((string) $name);

        $name = preg_replace(
            '/[^\pL\pN._-]+/u',
            '_',
            $name
        );

        $name = trim($name, '._-');

        return $name !== ''
            ? $name
            : 'file';
    }
}