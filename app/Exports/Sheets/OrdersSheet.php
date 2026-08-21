<?php

namespace App\Exports\Sheets;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;

class OrdersSheet implements
    FromCollection,
    WithTitle,
    ShouldAutoSize,
    WithEvents
{
    /**
     * @var \Illuminate\Support\Collection
     */
    private $orders;

    public function __construct(Collection $orders)
    {
        $this->orders = $orders;
    }

    public function collection()
    {
        $rows = collect();

        $rows->push([
            'ID замовлення',
            'GUID',
            'Дата замовлення',
            'Дата створення ТТН',
            'Номер ТТН',
            'Одержувач',
            'Телефон',
            'Місто',
            'Відділення / адреса',
            'Спосіб доставки',
            'Статус',
            'Кількість позицій',
            'Кількість товарів',
            'Сума замовлення',
        ]);

        foreach ($this->orders as $order) {
            $items = $order->items ?: collect();

            $rows->push([
                $order->id,
                $order->guid,
                $this->formatDate($order->created_at),
                $this->formatDate($order->ttn_created_at),
                $order->tracking_number,
                $this->customerName($order),
                $this->firstValue($order, [
                    'recipient_phone',
                    'customer_phone',
                    'phone',
                    'delivery_phone',
                ]),
                $this->firstValue($order, [
                    'recipient_city',
                    'delivery_city',
                    'city',
                ]),
                $this->firstValue($order, [
                    'recipient_address',
                    'delivery_address',
                    'warehouse_name',
                    'warehouse',
                    'address',
                ]),
                $this->firstValue($order, [
                    'delivery_type',
                    'delivery_method',
                    'shipping_method',
                ]),
                $this->firstValue($order, [
                    'status',
                    'status_name',
                ]),
                $items->count(),
                $items->sum(function ($item) {
                    return (float) $this->firstValue($item, [
                        'quantity',
                        'qty',
                        'amount',
                    ], 0);
                }),
                (float) $this->firstValue($order, [
                    'total',
                    'total_sum',
                    'total_price',
                    'amount',
                ], 0),
            ]);
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Замовлення';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $lastRow = $sheet->getHighestRow();
                $lastColumn = $sheet->getHighestColumn();

                $sheet->freezePane('A2');

                $sheet->setAutoFilter(
                    'A1:' . $lastColumn . $lastRow
                );

                $sheet->getStyle(
                    'A1:' . $lastColumn . '1'
                )->getFont()->setBold(true);

                $sheet->getStyle(
                    'A1:' . $lastColumn . '1'
                )->getAlignment()->setHorizontal(
                    Alignment::HORIZONTAL_CENTER
                );

                $sheet->getStyle(
                    'A1:' . $lastColumn . $lastRow
                )->getAlignment()->setVertical(
                    Alignment::VERTICAL_TOP
                );

                $sheet->getStyle(
                    'A1:' . $lastColumn . $lastRow
                )->getAlignment()->setWrapText(true);
            },
        ];
    }

    private function customerName($order): string
    {
        $fullName = $this->firstValue($order, [
            'recipient_name',
            'customer_name',
            'full_name',
            'client_name',
        ]);

        if ($fullName !== '') {
            return (string) $fullName;
        }

        $parts = array_filter([
            $this->firstValue($order, [
                'recipient_last_name',
                'last_name',
                'surname',
            ]),
            $this->firstValue($order, [
                'recipient_first_name',
                'first_name',
                'name',
            ]),
            $this->firstValue($order, [
                'recipient_middle_name',
                'middle_name',
                'patronymic',
            ]),
        ]);

        return implode(' ', $parts);
    }

    private function firstValue(
        $model,
        array $paths,
        $default = ''
    ) {
        foreach ($paths as $path) {
            $value = data_get($model, $path);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private function formatDate($value): string
    {
        if (!$value) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d.m.Y H:i');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }
}