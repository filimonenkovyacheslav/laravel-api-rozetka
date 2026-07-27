<?php

namespace App\Exports\Sheets;

use App\Models\ProductDimension;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class OrderItemsSheet implements
    FromCollection,
    WithTitle,
    ShouldAutoSize,
    WithEvents,
    WithColumnFormatting
{
    /**
     * Замовлення, які потрапляють до експорту.
     *
     * @var \Illuminate\Support\Collection
     */
    private $orders;

    /**
     * Назви товарів із таблиці product_dimensions.
     *
     * Формат:
     *
     * [
     *     '7777777' => 'Унітаз підвісний...',
     *     '444444'  => 'Душова кабіна...',
     * ]
     *
     * @var array
     */
    private $productNames = [];

    public function __construct(Collection $orders)
    {
        $this->orders = $orders;

        /*
         * Гарантуємо, що позиції замовлень завантажені.
         */
        foreach ($this->orders as $order) {
            if (method_exists($order, 'loadMissing')) {
                $order->loadMissing('items');
            }
        }

        /*
         * Один раз завантажуємо назви всіх необхідних
         * товарів, щоб не робити SQL-запит для кожного рядка.
         */
        $this->loadProductNames();
    }

    public function collection()
    {
        $rows = collect();

        $rows->push([
            'ID замовлення',
            'GUID',
            'Номер ТТН',
            'Артикул',
            'Назва товару',
            'Кількість',
            'Ціна',
            'Сума',
        ]);

        foreach ($this->orders as $order) {
            foreach ($order->items as $item) {
                $article = $this->normalizeArticle(
                    $this->firstValue(
                        $item,
                        [
                            'rz_code',
                            'sku',
                            'article',
                            'offer_id',
                            'product_id',
                        ]
                    )
                );

                /*
                 * Спочатку беремо назву з таблиці
                 * product_dimensions.
                 *
                 * Якщо там товар не знайдений —
                 * використовуємо назву з позиції замовлення.
                 */
                $productName = trim(
                    (string) (
                        $this->productNames[$article]
                        ?? $this->firstValue(
                            $item,
                            [
                                'name',
                                'product_name',
                                'title',
                            ]
                        )
                    )
                );

                if ($productName === '') {
                    $productName = 'Назву не знайдено';
                }

                $quantity = (float) $this->firstValue(
                    $item,
                    [
                        'quantity',
                        'qty',
                        'amount',
                        'reservedQuantity',
                        'reserved_quantity',
                    ],
                    0
                );

                $price = (float) $this->firstValue(
                    $item,
                    [
                        'price',
                        'unit_price',
                        'sale_price',
                    ],
                    0
                );

                $total = $this->firstValue(
                    $item,
                    [
                        'total',
                        'total_price',
                        'sum',
                    ]
                );

                if ($total === '') {
                    $total = $quantity * $price;
                }

                $rows->push([
                    (int) $order->id,

                    /*
                     * Передаємо як рядок, щоб Excel
                     * не змінював значення.
                     */
                    (string) $order->guid,

                    /*
                     * Номер ТТН також повинен бути текстом,
                     * інакше Excel може відобразити його
                     * у науковому форматі.
                     */
                    (string) $order->tracking_number,

                    /*
                     * Артикул передаємо рядком, щоб
                     * не втратити початкові нулі.
                     */
                    $article,

                    $productName,

                    $quantity,

                    round($price, 2),

                    round((float) $total, 2),
                ]);
            }
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Товари';
    }

    /**
     * Формати колонок Excel.
     */
    public function columnFormats(): array
    {
        return [
            /*
             * GUID, номер ТТН та артикул — текст.
             */
            'B' => NumberFormat::FORMAT_TEXT,
            'C' => NumberFormat::FORMAT_TEXT,
            'D' => NumberFormat::FORMAT_TEXT,

            /*
             * Кількість, ціна та сума.
             */
            'F' => '0.###',
            'G' => NumberFormat::FORMAT_NUMBER_00,
            'H' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $lastRow = $sheet->getHighestRow();
                $lastColumn = $sheet->getHighestColumn();

                /*
                 * Закріплюємо рядок заголовків.
                 */
                $sheet->freezePane('A2');

                /*
                 * Фільтр для всіх колонок.
                 */
                $sheet->setAutoFilter(
                    'A1:' . $lastColumn . $lastRow
                );

                /*
                 * Оформлення заголовків.
                 */
                $sheet->getStyle(
                    'A1:' . $lastColumn . '1'
                )->getFont()->setBold(true);

                $sheet->getStyle(
                    'A1:' . $lastColumn . '1'
                )->getAlignment()->setHorizontal(
                    Alignment::HORIZONTAL_CENTER
                );

                $sheet->getStyle(
                    'A1:' . $lastColumn . '1'
                )->getAlignment()->setVertical(
                    Alignment::VERTICAL_CENTER
                );

                /*
                 * Перенесення довгих назв товарів.
                 */
                $sheet->getStyle(
                    'A1:' . $lastColumn . $lastRow
                )->getAlignment()->setVertical(
                    Alignment::VERTICAL_TOP
                );

                $sheet->getStyle(
                    'A1:' . $lastColumn . $lastRow
                )->getAlignment()->setWrapText(true);

                /*
                 * Назви товарів вирівнюємо ліворуч.
                 */
                if ($lastRow >= 2) {
                    $sheet->getStyle(
                        'E2:E' . $lastRow
                    )->getAlignment()->setHorizontal(
                        Alignment::HORIZONTAL_LEFT
                    );
                }
            },
        ];
    }

    /**
     * Завантажує назви товарів із product_dimensions
     * для всіх артикулів поточного експорту.
     */
    private function loadProductNames()
    {
        $articles = collect();

        foreach ($this->orders as $order) {
            foreach ($order->items as $item) {
                $article = $this->normalizeArticle(
                    $this->firstValue(
                        $item,
                        [
                            'rz_code',
                            'sku',
                            'article',
                            'offer_id',
                            'product_id',
                        ]
                    )
                );

                if ($article !== '') {
                    $articles->push($article);
                }
            }
        }

        $articles = $articles
            ->unique()
            ->values();

        if ($articles->isEmpty()) {
            $this->productNames = [];

            return;
        }

        $products = ProductDimension::query()
            ->whereIn(
                'rz_code',
                $articles->all()
            )
            ->get([
                'rz_code',
                'name',
            ]);

        $names = [];

        foreach ($products as $product) {
            $article = $this->normalizeArticle(
                $product->rz_code
            );

            $name = trim(
                (string) $product->name
            );

            if (
                $article !== ''
                && $name !== ''
            ) {
                $names[$article] = $name;
            }
        }

        $this->productNames = $names;
    }

    /**
     * Приводить артикул до однакового формату
     * для порівняння з product_dimensions.rz_code.
     */
    private function normalizeArticle($value)
    {
        return trim((string) $value);
    }

    private function firstValue(
        $model,
        array $paths,
        $default = ''
    ) {
        foreach ($paths as $path) {
            $value = data_get($model, $path);

            if (
                $value !== null
                && $value !== ''
            ) {
                return $value;
            }
        }

        return $default;
    }
}