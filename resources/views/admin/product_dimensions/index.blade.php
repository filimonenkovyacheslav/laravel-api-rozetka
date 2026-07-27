@extends('admin.layout')

@section('title', 'Габарити товарів')
@section('header', 'Габарити та вага товарів')

@section('content')

<style>
    .products-table-wrapper {
        max-height: calc(100vh - 430px);
        overflow: auto;
        position: relative;
    }

    .products-table-wrapper > table {
        border-collapse: separate;
        border-spacing: 0;
    }

    .products-table-wrapper > table > thead > tr > th {
        position: sticky;
        top: 0;
        z-index: 20;
        background-color: #f8f9fa;
        border-top: 0;
        box-shadow: 0 1px 0 #dee2e6;
    }

    .product-summary-row td {
        vertical-align: middle;
    }

    .places-container {
        padding: 15px;
        background: #f8f9fa;
    }

    .places-table {
        margin-bottom: 0;
        background: #fff;
    }

    .places-table th {
        white-space: nowrap;
    }

    .places-table .form-control-sm {
        min-width: 110px;
    }

    .places-table .place-number {
        width: 60px;
        text-align: center;
        vertical-align: middle;
        font-weight: 600;
    }

    .places-table .place-actions {
        width: 120px;
        text-align: center;
        vertical-align: middle;
    }

    .product-code-input {
        min-width: 170px;
    }

    .product-actions {
        white-space: nowrap;
    }

    .places-hidden {
        display: none;
    }
</style>

@if($errors->any())
    <div class="alert alert-danger">
        <strong>Не вдалося зберегти дані.</strong>

        <ul class="mb-0 mt-2">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Добавление товара и первого места --}}
<div class="card mb-4">
    <div class="card-header">
        <strong>Додати новий товар</strong>
    </div>

    <div class="card-body">
        <form
            method="post"
            action="{{ route('admin.product-dimensions.store') }}"
        >
            @csrf

            <div class="form-row">
                <div class="col-lg-2 col-md-4 mb-3">
                    <label for="rz_code">
                        Артикул Розетки
                    </label>

                    <input
                        id="rz_code"
                        type="text"
                        name="rz_code"
                        class="form-control
                            @error('rz_code') is-invalid @enderror"
                        value="{{ old('rz_code') }}"
                        required
                    >

                    @error('rz_code')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <div class="col-lg-4 col-md-8 mb-3">
                    <label for="name">
                        Назва товару
                    </label>

                    <input
                        id="name"
                        type="text"
                        name="name"
                        class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name') }}"
                        placeholder="Введіть назву товару"
                        maxlength="255"
                        required
                    >

                    @error('name')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <div class="col-lg-1 col-md-3 mb-3">
                    <label for="weight">
                        Вага, кг
                    </label>

                    <input
                        id="weight"
                        type="number"
                        name="weight"
                        class="form-control
                            @error('weight') is-invalid @enderror"
                        value="{{ old('weight') }}"
                        min="1"
                        step="1"
                        required
                    >

                    @error('weight')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <div class="col-lg-1 col-md-3 mb-3">
                    <label for="length">
                        Довж., см
                    </label>

                    <input
                        id="length"
                        type="number"
                        name="length"
                        class="form-control
                            @error('length') is-invalid @enderror"
                        value="{{ old('length') }}"
                        min="1"
                        step="1"
                        required
                    >

                    @error('length')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <div class="col-lg-1 col-md-3 mb-3">
                    <label for="width">
                        Шир., см
                    </label>

                    <input
                        id="width"
                        type="number"
                        name="width"
                        class="form-control
                            @error('width') is-invalid @enderror"
                        value="{{ old('width') }}"
                        min="1"
                        step="1"
                        required
                    >

                    @error('width')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <div class="col-lg-1 col-md-3 mb-3">
                    <label for="height">
                        Вис., см
                    </label>

                    <input
                        id="height"
                        type="number"
                        name="height"
                        class="form-control
                            @error('height') is-invalid @enderror"
                        value="{{ old('height') }}"
                        min="1"
                        step="1"
                        required
                    >

                    @error('height')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <div class="col-lg-2 col-md-4 mb-3 d-flex align-items-end">
                    <button
                        type="submit"
                        class="btn btn-primary btn-block"
                    >
                        Додати товар
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Поиск и общее сохранение --}}
<div class="card mb-3">
    <div class="card-body">
        <div class="form-row align-items-end">
            <div class="col-md-6 mb-2">
                <form
                    method="get"
                    action="{{ route(
                        'admin.product-dimensions.index'
                    ) }}"
                >
                    <label for="search-rz-code">
                        Пошук за артикулом або назвою
                    </label>

                    <div class="input-group">
                        <input
                            id="search-rz-code"
                            type="text"
                            name="q"
                            class="form-control"
                            value="{{ $q }}"
                            placeholder="Артикул або назва товару"
                        >

                        <div class="input-group-append">
                            <button
                                type="submit"
                                class="btn btn-outline-primary"
                            >
                                Знайти
                            </button>

                            <a
                                href="{{ route(
                                    'admin.product-dimensions.index'
                                ) }}"
                                class="btn btn-outline-secondary"
                            >
                                Скинути
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <div class="col-md-auto mb-2">
                <button
                    type="submit"
                    form="products-bulk-form"
                    class="btn btn-success"
                    onclick="return confirm(
                        'Зберегти всі товари та місця на цій сторінці?'
                    )"
                >
                    Зберегти таблицю
                </button>
            </div>
        </div>
    </div>
</div>

<form
    id="products-bulk-form"
    method="post"
    action="{{ route(
        'admin.product-dimensions.bulk-update',
        [
            'q' => request('q'),
            'page' => request('page'),
        ]
    ) }}"
>
    @csrf

    <div class="card mb-4">
        <div class="card-header d-flex align-items-center">
            <strong>Товари</strong>

            <span class="text-muted ml-2">
                Всього: {{ $products->total() }}
            </span>
        </div>

        <div class="table-responsive products-table-wrapper">
            <table class="table table-sm table-bordered table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th style="width: 80px;">
                            №
                        </th>

                        <th style="width: 150px;">
                            Артикул Розетки
                        </th>

                        <th style="width: 300px;">
                            Назва товару
                        </th>

                        <th style="width: 60px;">
                            Кількість місць
                        </th>

                        <th style="width: 80px;">
                            Загальна вага
                        </th>

                        <th style="width: 150px;">
                            Оновлено
                        </th>

                        <th style="width: 280px;">
                            Дії
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($products as $productIndex => $product)
                        @php
                            $oldPlaces = old(
                                'products.' .
                                $productIndex .
                                '.places'
                            );

                            if (is_array($oldPlaces)) {
                                $placesForView = $oldPlaces;
                            } else {
                                $placesForView = [];

                                foreach ($product->places as $placeIndex => $place) {
                                    $placesForView[$placeIndex] = [
                                        'id' => $place->id,
                                        'weight' => $place->weight,
                                        'length' => $place->length,
                                        'width' => $place->width,
                                        'height' => $place->height,
                                    ];
                                }
                            }

                            $productNumber =
                                $products->total()
                                - (
                                    ($products->currentPage() - 1)
                                    * $products->perPage()
                                )
                                - $productIndex;

                            $placesCount = count($placesForView);

                            $totalWeight = collect($placesForView)
                                ->sum(function ($place) {
                                    return isset($place['weight'])
                                        ? (float) $place['weight']
                                        : 0;
                                });
                        @endphp

                        <tr class="product-summary-row">
                            <td>
                                {{ $productNumber }}

                                <input
                                    type="hidden"
                                    name="products[{{ $productIndex }}][id]"
                                    value="{{ $product->id }}"
                                >
                            </td>

                            <td>
                                <input
                                    type="text"
                                    name="products[{{ $productIndex }}][rz_code]"
                                    class="form-control form-control-sm
                                        product-code-input"
                                    value="{{ old(
                                        'products.' .
                                        $productIndex .
                                        '.rz_code',
                                        $product->rz_code
                                    ) }}"
                                    required
                                >
                            </td>

                            <td>
                                <input
                                    type="text"
                                    name="products[{{ $productIndex }}][name]"
                                    class="form-control form-control-sm"
                                    value="{{ old(
                                        'products.' . $productIndex . '.name',
                                        $product->name
                                    ) }}"
                                    placeholder="Назва товару"
                                    maxlength="255"
                                >
                            </td>

                            <td>
                                <span
                                    id="places-count-{{ $product->id }}"
                                    class="badge badge-info"
                                >
                                    {{ $placesCount }}
                                </span>
                            </td>

                            <td>
                                <strong>
                                    <span
                                        id="total-weight-{{ $product->id }}"
                                    >
                                        {{ number_format(
                                            $totalWeight,
                                            1,
                                            '.',
                                            ''
                                        ) }}
                                    </span>
                                    кг
                                </strong>
                            </td>

                            <td>
                                {{ optional($product->updated_at)
                                    ->format('d-m-Y H:i') }}
                            </td>

                            <td class="product-actions">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary
                                        toggle-places"
                                    data-product-id="{{ $product->id }}"
                                >
                                    Місця ({{ $placesCount }})
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-success
                                        add-place"
                                    data-product-id="{{ $product->id }}"
                                    data-product-index="{{ $productIndex }}"
                                >
                                    + Додати місце
                                </button>

                                <button
                                    type="submit"
                                    form="delete-product-{{ $product->id }}"
                                    class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm(
                                        'Видалити товар {{ $product->rz_code }} та всі його місця?'
                                    )"
                                >
                                    Видалити
                                </button>
                            </td>
                        </tr>

                        <tr
                            id="places-panel-{{ $product->id }}"
                            class="places-hidden"
                        >
                            <td colspan="7" class="p-0">
                                <div class="places-container">
                                    <div
                                        class="d-flex
                                            justify-content-between
                                            align-items-center mb-2"
                                    >
                                        <strong>
                                            Упаковочні місця товару
                                            {{ $product->rz_code }}
                                        </strong>

                                        <small class="text-muted">
                                            Видалення місця застосовується
                                            після збереження таблиці
                                        </small>
                                    </div>

                                    <div class="table-responsive">
                                        <table
                                            class="table table-sm
                                                table-bordered places-table"
                                        >
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>№ місця</th>
                                                    <th>Вага, кг</th>
                                                    <th>Довжина, см</th>
                                                    <th>Ширина, см</th>
                                                    <th>Висота, см</th>
                                                    <th>Дії</th>
                                                </tr>
                                            </thead>

                                            <tbody
                                                id="places-body-{{ $product->id }}"
                                                data-product-id="{{ $product->id }}"
                                                data-product-index="{{ $productIndex }}"
                                            >
                                                @foreach(
                                                    $placesForView
                                                    as $placeKey => $place
                                                )
                                                    <tr class="place-row">
                                                        <td
                                                            class="place-number"
                                                        >
                                                            {{ $loop->iteration }}

                                                            @if(!empty($place['id']))
                                                                <input
                                                                    type="hidden"
                                                                    name="products[{{ $productIndex }}][places][{{ $placeKey }}][id]"
                                                                    value="{{ $place['id'] }}"
                                                                >
                                                            @endif
                                                        </td>

                                                        <td>
                                                            <input
                                                                type="number"
                                                                name="products[{{ $productIndex }}][places][{{ $placeKey }}][weight]"
                                                                class="form-control
                                                                    form-control-sm
                                                                    place-weight"
                                                                value="{{ $place['weight'] ?? '' }}"
                                                                min="1"
                                                                step="1"
                                                                required
                                                            >
                                                        </td>

                                                        <td>
                                                            <input
                                                                type="number"
                                                                name="products[{{ $productIndex }}][places][{{ $placeKey }}][length]"
                                                                class="form-control
                                                                    form-control-sm"
                                                                value="{{ $place['length'] ?? '' }}"
                                                                min="1"
                                                                step="1"
                                                                required
                                                            >
                                                        </td>

                                                        <td>
                                                            <input
                                                                type="number"
                                                                name="products[{{ $productIndex }}][places][{{ $placeKey }}][width]"
                                                                class="form-control
                                                                    form-control-sm"
                                                                value="{{ $place['width'] ?? '' }}"
                                                                min="1"
                                                                step="1"
                                                                required
                                                            >
                                                        </td>

                                                        <td>
                                                            <input
                                                                type="number"
                                                                name="products[{{ $productIndex }}][places][{{ $placeKey }}][height]"
                                                                class="form-control
                                                                    form-control-sm"
                                                                value="{{ $place['height'] ?? '' }}"
                                                                min="1"
                                                                step="1"
                                                                required
                                                            >
                                                        </td>

                                                        <td
                                                            class="place-actions"
                                                        >
                                                            <button
                                                                type="button"
                                                                class="btn btn-sm
                                                                    btn-outline-danger
                                                                    remove-place"
                                                            >
                                                                Прибрати
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="7"
                                class="text-center text-muted p-4"
                            >
                                Товарів ще немає
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</form>

{{-- Формы удаления товара --}}
@foreach($products as $product)
    <form
        id="delete-product-{{ $product->id }}"
        method="post"
        action="{{ route(
            'admin.product-dimensions.destroy',
            $product->id
        ) }}"
        class="d-none"
    >
        @csrf
    </form>
@endforeach

@if($products->hasPages())
    <div class="my-4">
        {{ $products->links() }}
    </div>
@endif

<script>
document.addEventListener('DOMContentLoaded', function () {
    var newPlaceCounter = Date.now();

    document.addEventListener('click', function (event) {
        var toggleButton = event.target.closest('.toggle-places');

        if (toggleButton) {
            var toggleProductId =
                toggleButton.getAttribute('data-product-id');

            togglePlaces(toggleProductId);

            return;
        }

        var addButton = event.target.closest('.add-place');

        if (addButton) {
            var addProductId =
                addButton.getAttribute('data-product-id');

            var addProductIndex =
                addButton.getAttribute('data-product-index');

            addPlace(addProductId, addProductIndex);

            return;
        }

        var removeButton = event.target.closest('.remove-place');

        if (removeButton) {
            var row = removeButton.closest('.place-row');
            var tbody = row.closest('tbody');
            var productId = tbody.getAttribute('data-product-id');
            var rows = tbody.querySelectorAll('.place-row');

            if (rows.length <= 1) {
                alert(
                    'У товару повинно залишитися хоча б одне місце.'
                );

                return;
            }

            if (!confirm('Прибрати це упаковочне місце?')) {
                return;
            }

            row.remove();
            recalculateProduct(productId);
        }
    });

    document.addEventListener('input', function (event) {
        if (!event.target.classList.contains('place-weight')) {
            return;
        }

        var tbody = event.target.closest('tbody');

        if (!tbody) {
            return;
        }

        recalculateProduct(
            tbody.getAttribute('data-product-id')
        );
    });

    function togglePlaces(productId)
    {
        var panel = document.getElementById(
            'places-panel-' + productId
        );

        if (!panel) {
            return;
        }

        if (panel.classList.contains('places-hidden')) {
            panel.classList.remove('places-hidden');
        } else {
            panel.classList.add('places-hidden');
        }
    }

    function addPlace(productId, productIndex)
    {
        var tbody = document.getElementById(
            'places-body-' + productId
        );

        var panel = document.getElementById(
            'places-panel-' + productId
        );

        if (!tbody || !panel) {
            return;
        }

        newPlaceCounter++;

        var placeKey = 'new_' + newPlaceCounter;

        var row = document.createElement('tr');
        row.className = 'place-row';

        row.innerHTML =
            '<td class="place-number"></td>' +

            '<td>' +
                '<input ' +
                    'type="number" ' +
                    'name="products[' + productIndex + ']' +
                        '[places][' + placeKey + '][weight]" ' +
                    'class="form-control form-control-sm place-weight" ' +
                    'min="1" ' +
                    'step="1" ' +
                    'required' +
                '>' +
            '</td>' +

            '<td>' +
                '<input ' +
                    'type="number" ' +
                    'name="products[' + productIndex + ']' +
                        '[places][' + placeKey + '][length]" ' +
                    'class="form-control form-control-sm" ' +
                    'min="1" ' +
                    'step="1" ' +
                    'required' +
                '>' +
            '</td>' +

            '<td>' +
                '<input ' +
                    'type="number" ' +
                    'name="products[' + productIndex + ']' +
                        '[places][' + placeKey + '][width]" ' +
                    'class="form-control form-control-sm" ' +
                    'min="1" ' +
                    'step="1" ' +
                    'required' +
                '>' +
            '</td>' +

            '<td>' +
                '<input ' +
                    'type="number" ' +
                    'name="products[' + productIndex + ']' +
                        '[places][' + placeKey + '][height]" ' +
                    'class="form-control form-control-sm" ' +
                    'min="1" ' +
                    'step="1" ' +
                    'required' +
                '>' +
            '</td>' +

            '<td class="place-actions">' +
                '<button ' +
                    'type="button" ' +
                    'class="btn btn-sm btn-outline-danger ' +
                        'remove-place"' +
                '>' +
                    'Прибрати' +
                '</button>' +
            '</td>';

        tbody.appendChild(row);
        panel.classList.remove('places-hidden');

        recalculateProduct(productId);

        var firstInput = row.querySelector('input');

        if (firstInput) {
            firstInput.focus();
        }
    }

    function recalculateProduct(productId)
    {
        var tbody = document.getElementById(
            'places-body-' + productId
        );

        if (!tbody) {
            return;
        }

        var rows = tbody.querySelectorAll('.place-row');
        var totalWeight = 0;

        rows.forEach(function (row, index) {
            var numberCell = row.querySelector('.place-number');

            if (numberCell) {
                /*
                 * Сохраняем hidden input с ID существующего места.
                 */
                var hiddenInput = numberCell.querySelector(
                    'input[type="hidden"]'
                );

                numberCell.childNodes[0].nodeValue =
                    (index + 1) + ' ';

                if (hiddenInput) {
                    numberCell.appendChild(hiddenInput);
                }
            }

            var weightInput = row.querySelector('.place-weight');

            if (weightInput) {
                var weight = parseFloat(weightInput.value);

                if (!isNaN(weight)) {
                    totalWeight += weight;
                }
            }
        });

        var countElement = document.getElementById(
            'places-count-' + productId
        );

        if (countElement) {
            countElement.textContent = rows.length;
        }

        var totalWeightElement = document.getElementById(
            'total-weight-' + productId
        );

        if (totalWeightElement) {
            totalWeightElement.textContent =
                totalWeight.toFixed(3);
        }

        var toggleButton = document.querySelector(
            '.toggle-places[data-product-id="' +
                productId +
            '"]'
        );

        if (toggleButton) {
            toggleButton.textContent =
                'Місця (' + rows.length + ')';
        }
    }
});
</script>

@endsection