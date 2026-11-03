<?php

namespace App\Support;

/**
 * Human-readable status labels for the new Rozetka Supplier API.
 * Legacy order statuses are intentionally handled separately.
 */
class RozetkaStatusPresenter
{
    public static function orderStatuses()
    {
        return [
            'new' => ['label' => 'Нове', 'color' => '#337ab7'],
            'updated' => ['label' => 'Оновлено', 'color' => '#ffc107'],
            'deleted' => ['label' => 'Скасовано', 'color' => '#d9534f'],
            'confirmed' => ['label' => 'Підтверджено', 'color' => '#5cb85c'],
            'shipping' => ['label' => 'Доставка', 'color' => '#5bc0de'],
            'closed' => ['label' => 'Закрито', 'color' => '#333333'],
            'delivery_refused' => ['label' => 'Відмова від отримання', 'color' => '#a94442'],
            'problem' => ['label' => 'Проблема', 'color' => '#a94442'],
            'shipped_from_rozetka' => ['label' => 'Доставка від Розетки', 'color' => '#5bc0de'],
        ];
    }

    public static function orderStatus($status)
    {
        return self::orderStatuses()[$status] ?? [
            'label' => $status ?: 'Невідомий',
            'color' => '#6c757d',
        ];
    }

    public static function uzdLabel($status)
    {
        $labels = [
            'not_uploaded' => 'Не завантажено',
            'verified' => 'Пройшло звірку',
            'problem' => 'Є проблеми',
            'skipped' => 'Перевірку пропущено',
        ];

        return $labels[$status] ?? 'Невідомий';
    }

    public static function canConfirm($order)
    {
        return in_array(
            (string) $order->rozetka_status,
            ['new', 'updated', 'confirmed'],
            true
        ) && trim((string) $order->tracking_number) === '';
    }
}
