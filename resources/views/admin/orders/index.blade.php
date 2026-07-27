@extends('admin.layout')

@section('title', 'Orders')
@section('header', 'Orders')

@section('content')

<style>
    .tr-danger {
        color: #fff;
        background: #f57c73;
    }

    .tr-danger .btn-outline-primary {
        color: #fff;
        border-color: #fff;
    }

    .tr-danger .text-muted {
        color: #fff !important;
    }

    .orders-table-wrapper {
        max-height: calc(100vh - 420px);
        overflow: auto;
        position: relative;
    }

    .orders-table-wrapper table {
        border-collapse: separate;
        border-spacing: 0;
    }

    .orders-table-wrapper thead th {
        position: sticky;
        top: 0;
        z-index: 10;
        background-color: #f8f9fa;
        border-top: 0;
        box-shadow: 0 1px 0 #dee2e6;
        white-space: nowrap;
    }

    .bulk-order-checkbox,
    #select-all-orders {
        width: 17px;
        height: 17px;
        cursor: pointer;
    }

    .files-status {
        min-width: 130px;
    }

    .bulk-actions-card .btn {
        margin-right: 8px;
        margin-bottom: 5px;
    }

    .selected-orders-count {
        font-weight: 600;
    }

    .order-details-row {
        display: none;
    }

    .order-details-row.is-open {
        display: table-row;
    }

    .order-details-row > td {
        padding: 0 !important;
        border-top: 0;
        background: rgb(23 162 184 / 40%);
    }

    .order-details-box {
        padding: 16px 18px;
        border-top: 2px solid #dee2e6;
        border-bottom: 2px solid #dee2e6;
    }

    .order-details-title {
        margin-bottom: 8px;
        font-weight: 600;
    }

    .order-details-data {
        margin-bottom: 14px;
    }

    .order-details-data dt {
        font-weight: 600;
    }

    .order-details-data dd {
        margin-bottom: 5px;
    }

    .order-items-preview {
        margin-bottom: 0;
        background: #fff;
    }

    .order-items-preview th {
        white-space: nowrap;
    }

    .order-summary-toggle .toggle-icon {
        display: inline-block;
        margin-right: 4px;
        transition: transform 0.15s ease;
    }

    .order-summary-toggle.is-open .toggle-icon {
        transform: rotate(180deg);
    }

    .order-actions {
        white-space: nowrap;
    }

    .order-details-empty {
        padding: 12px;
        color: #6c757d;
        background: #fff;
        border: 1px solid #dee2e6;
    }

    td.order-actions {
        display: flex;
        flex-direction: column;
    }

    td.order-actions a {
        margin: 15px 0;
    }
</style>

{{-- Фильтры --}}
<form
    class="card card-body mb-3"
    method="get"
    action="{{ route('admin.orders.index') }}"
>
    <div class="form-row">
        <div class="col-lg-3 col-md-6 mb-2">
            <label class="small mb-1">
                Пошук (GUID / Телефон / ТТН / Клієнт)
            </label>

            <input
                type="text"
                name="q"
                value="{{ $filters['q'] ?? '' }}"
                class="form-control"
                placeholder="Search..."
            >
        </div>

        <div class="col-lg-2 col-md-3 mb-2">
            <label class="small mb-1">
                Статус
            </label>

            <select
                name="status"
                class="form-control"
            >
                <option value="">
                    Всі
                </option>

                @foreach([
                    'pending',
                    'created',
                    'updated',
                    'shipped',
                    'canceled'
                ] as $status)
                    <option
                        value="{{ $status }}"
                        @if(($filters['status'] ?? '') === $status)
                            selected
                        @endif
                    >
                        {{ ucfirst($status) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="col-lg-2 col-md-3 mb-2">
            <label class="small mb-1">
                Файли
            </label>

            <select
                name="files_state"
                class="form-control"
            >
                <option value="">
                    Всі
                </option>

                <option
                    value="new"
                    @if(
                        ($filters['files_state'] ?? '') === 'new'
                    )
                        selected
                    @endif
                >
                    Нові файли
                </option>

                <option
                    value="downloaded"
                    @if(
                        ($filters['files_state'] ?? '') ===
                        'downloaded'
                    )
                        selected
                    @endif
                >
                    Завантажені
                </option>

                <option
                    value="none"
                    @if(
                        ($filters['files_state'] ?? '') === 'none'
                    )
                        selected
                    @endif
                >
                    Немає файлів
                </option>
            </select>
        </div>

        <div class="col-lg-1 col-md-3 mb-2">
            <label class="small mb-1">
                Від
            </label>

            <input
                type="date"
                name="from"
                value="{{ $filters['from'] ?? '' }}"
                class="form-control"
            >
        </div>

        <div class="col-lg-1 col-md-3 mb-2">
            <label class="small mb-1">
                До
            </label>

            <input
                type="date"
                name="to"
                value="{{ $filters['to'] ?? '' }}"
                class="form-control"
            >
        </div>

        <div class="col-lg-2 col-md-3 mb-2">
            <label class="small mb-1">
                Замовлень на сторінці
            </label>

            <select
                name="per_page"
                class="form-control"
            >
                @foreach([10, 20, 50, 100] as $perPage)
                    <option
                        value="{{ $perPage }}"
                        @if(
                            (int) ($filters['per_page'] ?? 20) ===
                            $perPage
                        )
                            selected
                        @endif
                    >
                        {{ $perPage }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="mt-2">
        <button
            type="submit"
            class="btn btn-primary"
        >
            Застосувати
        </button>

        <a
            class="btn btn-link"
            href="{{ route('admin.orders.index') }}"
        >
            Скасувати
        </a>
    </div>
</form>

{{--
    Общая форма для всех массовых операций.

    Чекбоксы находятся в таблице, но относятся к этой форме
    через атрибут form="bulk-orders-form".
--}}
<form
    id="bulk-orders-form"
    method="post"
    action="{{ route('admin.order-files.bulk-download') }}"
>
    @csrf
</form>

{{-- Массовые действия --}}
<div class="card card-body mb-3 bulk-actions-card">
    <div class="d-flex flex-wrap align-items-center">
        <button
            type="submit"
            form="bulk-orders-form"
            formaction="{{ route(
                'admin.order-files.bulk-download'
            ) }}"
            name="mode"
            value="new"
            class="btn btn-primary"
        >
            Завантажити нові файли
        </button>

        <button
            type="submit"
            form="bulk-orders-form"
            formaction="{{ route(
                'admin.order-files.bulk-download'
            ) }}"
            name="mode"
            value="all"
            class="btn btn-outline-primary"
            onclick="return confirm(
                'Повторно завантажити всі файли вибраних замовлень?'
            )"
        >
            Завантажити повторно
        </button>

        <button
            type="submit"
            id="create-ttn-batch-button"
            form="bulk-orders-form"
            formaction="{{ route('admin.ttn-batches.store') }}"
            formmethod="post"
            class="btn btn-primary"
        >
            Масово сформувати ТТН
        </button>

        <span class="text-muted small ml-2">
            Вибрано замовлень:
            <span
                id="selected-orders-count"
                class="selected-orders-count"
            >
                0
            </span>
        </span>
    </div>
</div>

{{-- Таблица заказов --}}
<div class="card">

    <div class="card-header d-flex align-items-center">
        <strong>Замовлення</strong>

        <span class="text-muted ml-2">
            Всього: {{ $orders->total() }}
        </span>
    </div>
    
    <div class="table-responsive orders-table-wrapper">
        <table class="table table-sm table-hover mb-0">
            <thead class="thead-light">
                <tr>
                    <th
                        style="width: 42px;"
                        class="text-center"
                    >
                        <input
                            type="checkbox"
                            id="select-all-orders"
                            title="Вибрати всі замовлення на сторінці"
                        >
                    </th>

                    <th>ID</th>
                    <th>GUID</th>
                    <th>Клієнт</th>
                    <th>Телефон</th>
                    <th>Статус</th>
                    <th>Контроль оплати</th>
                    <th>ТТН</th>
                    <th>Файли</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
                @forelse($orders as $order)
                    @php
                        $statusClass = [
                            'pending' => 'badge-warning',
                            'created' => 'badge-primary',
                            'updated' => 'badge-info',
                            'shipped' => 'badge-success',
                            'canceled' => 'badge-danger',
                        ][$order->status] ?? 'badge-secondary';

                        $filesCount = (int) (
                            $order->files_count ?? 0
                        );

                        $newFilesCount = (int) (
                            $order->new_files_count ?? 0
                        );

                        $hasFiles = $filesCount > 0;
                        $hasNewFiles = $newFilesCount > 0;

                        $allFilesDownloaded =
                            $hasFiles && !$hasNewFiles;
                    @endphp

                    <tr
                        class="{{
                            $order->cash_on_delivery > 0
                                ? 'tr-danger'
                                : ''
                        }}"
                    >
                        {{-- Выбор заказа для любых массовых операций --}}
                        <td class="text-center align-middle">
                            <input
                                type="checkbox"
                                name="order_ids[]"
                                value="{{ $order->id }}"
                                form="bulk-orders-form"
                                class="bulk-order-checkbox"
                                title="Вибрати замовлення"
                            >
                        </td>

                        <td class="align-middle">
                            {{ $order->id }}
                        </td>

                        <td class="mono align-middle">
                            {{ $order->guid }}
                        </td>

                        <td class="align-middle">
                            {{ $order->customer_name }}
                        </td>

                        <td class="mono align-middle">
                            {{ $order->delivery_phone }}
                        </td>

                        <td class="align-middle">
                            <span
                                class="badge {{ $statusClass }}
                                    badge-status"
                            >
                                {{ $order->status }}
                            </span>
                        </td>

                        <td class="align-middle">
                            <span class="mono">
                                {{ $order->cash_on_delivery }}
                            </span>
                        </td>

                        <td class="mono align-middle">
                            {{ $order->tracking_number ?: '—' }}

                            @if($hasFiles)
                                <span
                                    class="badge badge-danger ml-1"
                                    title="Є файли"
                                >
                                    PDF
                                </span>
                            @endif
                        </td>

                        <td class="align-middle files-status">
                            @if(!$hasFiles)
                                <span class="badge badge-secondary">
                                    Немає файлів
                                </span>
                            @elseif($hasNewFiles)
                                <span class="badge badge-warning">
                                    Нові: {{ $newFilesCount }}
                                </span>

                                <div class="small text-muted mt-1">
                                    Усього: {{ $filesCount }}
                                </div>
                            @else
                                <span class="badge badge-success">
                                    ✓ Завантажено
                                </span>

                                <div class="small text-muted mt-1">
                                    Файлів: {{ $filesCount }}
                                </div>
                            @endif

                            <input
                                type="checkbox"
                                class="ml-1"
                                disabled
                                {{ $allFilesDownloaded
                                    ? 'checked'
                                    : ''
                                }}
                                title="Усі файли завантажені"
                            >
                        </td>

                        <td class="align-middle">
                            {{ optional($order->created_at)
                                ->format('d-m-Y H:i') }}
                        </td>

                        <td class="text-right align-middle order-actions">
                            <a
                                class="btn btn-sm btn-outline-primary"
                                href="{{ route(
                                    'admin.orders.show',
                                    $order->id
                                ) }}"
                            >
                                Подробиці
                            </a>
                            
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-info
                                    order-summary-toggle"
                                data-target="order-details-{{ $order->id }}"
                                aria-expanded="false"
                                title="Показати короткий зміст замовлення"
                            >
                                <span class="toggle-icon">▼</span>
                                Коротко
                            </button>
                            
                        </td>
                    </tr>
                    <tr
                        id="order-details-{{ $order->id }}"
                        class="order-details-row"
                    >
                        <td colspan="11">
                            <div class="order-details-box">                              

                                <div class="order-details-title">
                                    Товари
                                    <span class="text-muted font-weight-normal">
                                        ({{ $order->items->count() }})
                                    </span>
                                </div>

                                @if($order->items->isEmpty())
                                    <div class="order-details-empty">
                                        У замовленні немає товарів.
                                    </div>
                                @else
                                    <div class="table-responsive">
                                        <table
                                            class="table table-sm table-bordered
                                                order-items-preview"
                                        >
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Артикул</th>
                                                    <th>Назва товару</th>
                                                    <th class="text-right">
                                                        Кількість
                                                    </th>
                                                    <th class="text-right">
                                                        Зарезервовано
                                                    </th>
                                                    <th class="text-right">
                                                        Ціна
                                                    </th>
                                                    <th class="text-right">
                                                        Сума
                                                    </th>
                                                </tr>
                                            </thead>

                                            <tbody>
                                                @foreach(
                                                    $order->items->take(5)
                                                    as $item
                                                )
                                                    @php
                                                        $article = trim(
                                                            (string) (
                                                                data_get(
                                                                    $item,
                                                                    'rz_code'
                                                                )
                                                                ?: data_get(
                                                                    $item,
                                                                    'sku'
                                                                )
                                                                ?: data_get(
                                                                    $item,
                                                                    'article'
                                                                )
                                                                ?: data_get(
                                                                    $item,
                                                                    'offer_id'
                                                                )
                                                                ?: data_get(
                                                                    $item,
                                                                    'product_id'
                                                                )
                                                                ?: ''
                                                            )
                                                        );

                                                        $productName = trim(
                                                            (string) (
                                                                $productNames[$article]
                                                                ?? data_get(
                                                                    $item,
                                                                    'name'
                                                                )
                                                                ?? data_get(
                                                                    $item,
                                                                    'product_name'
                                                                )
                                                                ?? data_get(
                                                                    $item,
                                                                    'title'
                                                                )
                                                                ?? ''
                                                            )
                                                        );

                                                        $quantity = data_get(
                                                            $item,
                                                            'quantity'
                                                        );

                                                        if (
                                                            $quantity === null
                                                            || $quantity === ''
                                                        ) {
                                                            $quantity = data_get(
                                                                $item,
                                                                'qty',
                                                                0
                                                            );
                                                        }

                                                        $reservedQuantity = data_get(
                                                            $item,
                                                            'reservedQuantity'
                                                        );

                                                        if (
                                                            $reservedQuantity === null
                                                            || $reservedQuantity === ''
                                                        ) {
                                                            $reservedQuantity = data_get(
                                                                $item,
                                                                'reserved_quantity',
                                                                0
                                                            );
                                                        }

                                                        $price = (float) (
                                                            data_get($item, 'price')
                                                            ?? data_get(
                                                                $item,
                                                                'unit_price'
                                                            )
                                                            ?? data_get(
                                                                $item,
                                                                'sale_price'
                                                            )
                                                            ?? 0
                                                        );

                                                        $itemTotal =
                                                            (float) $quantity * $price;
                                                    @endphp

                                                    <tr>
                                                        <td class="mono">
                                                            {{ $article ?: '—' }}
                                                        </td>

                                                        <td>
                                                            {{
                                                                $productName
                                                                    ?: 'Назву не знайдено'
                                                            }}
                                                        </td>

                                                        <td class="text-right">
                                                            {{ $quantity }}
                                                        </td>

                                                        <td class="text-right">
                                                            <strong>
                                                                {{ $reservedQuantity }}
                                                            </strong>
                                                        </td>

                                                        <td class="text-right">
                                                            {{
                                                                number_format(
                                                                    $price,
                                                                    2,
                                                                    '.',
                                                                    ' '
                                                                )
                                                            }}
                                                        </td>

                                                        <td class="text-right">
                                                            {{
                                                                number_format(
                                                                    $itemTotal,
                                                                    2,
                                                                    '.',
                                                                    ' '
                                                                )
                                                            }}
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>

                                    @if($order->items->count() > 5)
                                        <div class="small text-muted mt-2">
                                            Показано перші 5 товарів.
                                            Ще товарів:
                                            {{ $order->items->count() - 5 }}.
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td
                            colspan="11"
                            class="text-center text-muted p-4"
                        >
                            No orders
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($orders->hasPages())
    <div class="my-4">
        {{ $orders->links() }}
    </div>
@endif

<script>
document.addEventListener('DOMContentLoaded', function () {
    var selectAll = document.getElementById(
        'select-all-orders'
    );

    var bulkForm = document.getElementById(
        'bulk-orders-form'
    );

    var selectedCountElement = document.getElementById(
        'selected-orders-count'
    );

    function getOrderCheckboxes()
    {
        return document.querySelectorAll(
            '.bulk-order-checkbox'
        );
    }

    function getSelectedCheckboxes()
    {
        return document.querySelectorAll(
            '.bulk-order-checkbox:checked'
        );
    }

    function updateSelectionState()
    {
        var checkboxes = getOrderCheckboxes();
        var selected = getSelectedCheckboxes();

        if (selectedCountElement) {
            selectedCountElement.textContent =
                selected.length;
        }

        if (!selectAll) {
            return;
        }

        selectAll.checked =
            checkboxes.length > 0 &&
            selected.length === checkboxes.length;

        selectAll.indeterminate =
            selected.length > 0 &&
            selected.length < checkboxes.length;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var shouldSelect = selectAll.checked;

            getOrderCheckboxes().forEach(function (
                checkbox
            ) {
                checkbox.checked = shouldSelect;
            });

            updateSelectionState();
        });
    }

    document.addEventListener('change', function (event) {
        if (
            event.target.classList.contains(
                'bulk-order-checkbox'
            )
        ) {
            updateSelectionState();
        }
    });

    if (bulkForm) {
        bulkForm.addEventListener('submit', function (
            event
        ) {
            var selected = getSelectedCheckboxes();

            if (selected.length === 0) {
                event.preventDefault();

                alert(
                    'Оберіть хоча б одне замовлення.'
                );
            }
        });
    }

    /*
     * Краткое содержание заказа.
     */
    document.addEventListener('click', function (event) {
        var button = event.target.closest(
            '.order-summary-toggle'
        );

        if (!button) {
            return;
        }

        var targetId = button.getAttribute(
            'data-target'
        );

        var detailsRow = document.getElementById(
            targetId
        );

        if (!detailsRow) {
            return;
        }

        var willOpen = !detailsRow.classList.contains(
            'is-open'
        );

        detailsRow.classList.toggle(
            'is-open',
            willOpen
        );

        button.classList.toggle(
            'is-open',
            willOpen
        );

        button.setAttribute(
            'aria-expanded',
            willOpen ? 'true' : 'false'
        );

        var label = willOpen
            ? 'Закрити'
            : 'Коротко';

        button.innerHTML =
            '<span class="toggle-icon">▼</span> ' +
            label;
    });

    // NP TTN
    var createButton = document.getElementById(
        'create-ttn-batch-button'
    );

    if (!createButton) {
        return;
    }

    createButton.addEventListener('click', function (event) {
        var selected = document.querySelectorAll(
            '.bulk-order-checkbox:checked'
        );

        if (selected.length === 0) {
            event.preventDefault();

            alert(
                'Оберіть хоча б одне замовлення для формування ТТН.'
            );

            return;
        }

        var confirmed = confirm(
            'Створити пакет масового формування ТТН для вибраних замовлень?'
        );

        if (!confirmed) {
            event.preventDefault();
        }
    });
    // END NP TTN
    
    updateSelectionState();
});
</script>

@endsection