<?php

namespace App\Services\Rozetka;

use App\Models\TtnBatch;
use RuntimeException;

class RozetkaMarkingService
{
    /**
     * @var RozetkaClient
     */
    private $client;

    public function __construct(
        RozetkaClient $client
    ) {
        $this->client = $client;
    }

    /**
     * Получить PDF-маркировки для заказов
     * нового Rozetka Supplier API в пакете ТТН.
     *
     * @param TtnBatch $batch
     * @param string $size
     * @return array
     */
    public function printBatchPdf(
        TtnBatch $batch,
        $size = '85x85'
    ) {
        $allowedSizes = [
            '85x85',
            '100x100',
        ];

        if (
            !in_array(
                $size,
                $allowedSizes,
                true
            )
        ) {
            throw new RuntimeException(
                'Непідтримуваний розмір маркування: ' .
                $size
            );
        }

        /*
         * Печатать можно только успешно обработанные
         * записи пакета, у которых есть ТТН.
         *
         * Legacy-заказы отбрасываем по отсутствию
         * rozetka_status.
         */
        $entries = $batch->entries()
            ->with('order')
            ->where(
                'status',
                'success'
            )
            ->whereNotNull(
                'tracking_number'
            )
            ->orderBy('id')
            ->get()
            ->filter(function ($entry) {
                if (!$entry->order) {
                    return false;
                }

                if (
                    trim(
                        (string)
                        $entry->tracking_number
                    ) === ''
                ) {
                    return false;
                }

                return trim(
                    (string)
                    $entry->order->rozetka_status
                ) !== '';
            })
            ->values();

        if ($entries->isEmpty()) {
            throw new RuntimeException(
                'У пакеті немає успішних замовлень ' .
                'нового Rozetka API з ТТН.'
            );
        }

        /*
         * Rozetka print-markings принимает
         * максимум 100 identifiers за запрос.
         *
         * Не обрезаем список молча,
         * иначе пользователь получил бы
         * неполный комплект маркировок.
         */
        if ($entries->count() > 100) {
            throw new RuntimeException(
                'У пакеті ' .
                $entries->count() .
                ' замовлень Rozetka з ТТН. ' .
                'API дозволяє друкувати максимум ' .
                '100 маркувань за один запит.'
            );
        }

        $orderIds = $entries
            ->map(function ($entry) {
                return trim(
                    (string)
                    $entry->order->guid
                );
            })
            ->filter()
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            throw new RuntimeException(
                'Не знайдено orderId для друку маркувань.'
            );
        }

        if (
            $orderIds->count() !==
            $entries->count()
        ) {
            throw new RuntimeException(
                'У пакеті знайдено дублікати або ' .
                'порожні orderId Rozetka.'
            );
        }

        $result = $this->client->printMarkings([
            'deliveryService' =>
                'nova_poshta',

            'format' =>
                'pdf',

            'size' =>
                $size,

            'orders' =>
                $orderIds->all(),
        ]);

        if (
            !isset($result['body']) ||
            $result['body'] === ''
        ) {
            throw new RuntimeException(
                'Rozetka повернула порожній файл маркувань.'
            );
        }

        return [
            'body' =>
                $result['body'],

            'content_type' =>
                $result['content_type']
                ?: 'application/pdf',

            'content_disposition' =>
                $result['content_disposition']
                ?? null,

            'printed' =>
                (int) (
                    $result['printed']
                    ?? 0
                ),

            'skipped' =>
                (int) (
                    $result['skipped']
                    ?? 0
                ),

            'requested' =>
                $orderIds->count(),

            'order_ids' =>
                $orderIds->all(),
        ];
    }
}