<?php

namespace App\Exports;

use App\Exports\Sheets\OrderItemsSheet;
use App\Exports\Sheets\OrdersSheet;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class OrderReportExport implements WithMultipleSheets
{
    /**
     * @var \Illuminate\Support\Collection
     */
    private $orders;

    public function __construct(Collection $orders)
    {
        $this->orders = $orders;
    }

    public function sheets(): array
    {
        return [
            new OrdersSheet($this->orders),
            new OrderItemsSheet($this->orders),
        ];
    }
}