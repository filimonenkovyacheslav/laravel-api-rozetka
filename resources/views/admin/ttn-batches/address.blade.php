@extends('admin.layout')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">
                Уточнення адреси
            </h1>

            <div class="text-muted">
                Пакет №{{ $batch->id }},
                замовлення №{{ $order->id }}
            </div>
        </div>

        <a
            href="{{ route(
                'admin.ttn-batches.show',
                $batch
            ) }}"
            class="btn btn-outline-secondary ml-auto"
        >
            Назад до пакета
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            {{ $errors->first() }}
        </div>
    @endif

    @if($searchError)
        <div class="alert alert-danger">
            {{ $searchError }}
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header">
            Дані з замовлення
        </div>

        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-md-3">
                    Одержувач
                </dt>

                <dd class="col-md-9">
                    {{ $order->customer_name ?: '—' }}
                </dd>

                <dt class="col-md-3">
                    Населений пункт
                </dt>

                <dd class="col-md-9">
                    {{ $order->delivery_city ?: '—' }}
                </dd>

                <dt class="col-md-3">
                    Адреса
                </dt>

                <dd class="col-md-9">
                    {{ $order->delivery_street ?: '—' }}
                </dd>

                @if($parsedAddress)
                    <dt class="col-md-3">
                        Розібрана адреса
                    </dt>

                    <dd class="col-md-9">
                        {{ $parsedAddress['street'] }},
                        буд. {{ $parsedAddress['house'] }}

                        @if($parsedAddress['flat'] !== '')
                            , кв. {{ $parsedAddress['flat'] }}
                        @endif
                    </dd>
                @endif
            </dl>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            Крок 1. Оберіть населений пункт
        </div>

        <div class="card-body">
            @if(empty($settlementOptions))
                <div class="alert alert-warning mb-0">
                    Варіанти населеного пункту не знайдено.
                    Перевірте поле міста в замовленні.
                </div>
            @else
                <form
                    method="post"
                    action="{{ route(
                        'admin.ttn-batches.address.settlement',
                        [$batch, $entry]
                    ) }}"
                >
                    @csrf

                    @foreach($settlementOptions as $option)
                        @php
                            $checked =
                                old(
                                    'settlement_ref',
                                    $order
                                        ->np_recipient_settlement_ref
                                )
                                === $option['settlement_ref'];
                        @endphp

                        <div class="custom-control custom-radio mb-2">
                            <input
                                type="radio"
                                class="custom-control-input"
                                id="settlement-{{ $loop->index }}"
                                name="settlement_ref"
                                value="{{ $option['settlement_ref'] }}"
                                {{ $checked ? 'checked' : '' }}
                            >

                            <label
                                class="custom-control-label"
                                for="settlement-{{ $loop->index }}"
                            >
                                <strong>
                                    {{ $option['name'] }}
                                </strong>

                                @if($option['area'])
                                    — {{ $option['area'] }}
                                @endif

                                @if($option['region'])
                                    , {{ $option['region'] }}
                                @endif
                            </label>
                        </div>
                    @endforeach

                    <button
                        type="submit"
                        class="btn btn-primary mt-3"
                    >
                        Зберегти населений пункт
                    </button>
                </form>
            @endif
        </div>
    </div>

    @if(
        !empty($order->np_recipient_settlement_ref)
        && !empty($order->np_city_ref)
    )
        <div class="card mb-3">
            <div class="card-header">
                Крок 2. Оберіть вулицю
            </div>

            <div class="card-body">
                <div class="alert alert-info">
                    Обрано населений пункт:
                    <strong>
                        {{
                            $order->np_recipient_settlement_name
                            ?: $order->delivery_city
                        }}
                    </strong>
                </div>

                <form
                    method="get"
                    action="{{ route(
                        'admin.ttn-batches.address.show',
                        [$batch, $entry]
                    ) }}"
                    class="mb-4"
                >
                    <div class="form-group mb-2">
                        <label for="street-search">
                            Пошук вулиці у довіднику Нової Пошти
                        </label>

                        <div class="input-group">
                            <input
                                type="text"
                                id="street-search"
                                name="street_search"
                                class="form-control"
                                value="{{ $streetSearch }}"
                                placeholder="Наприклад: Ватутіна"
                                required
                            >

                            <div class="input-group-append">
                                <button
                                    type="submit"
                                    class="btn btn-outline-primary"
                                >
                                    Знайти вулицю
                                </button>
                            </div>
                        </div>

                        <small class="form-text text-muted">
                            Якщо вулицю було перейменовано,
                            спробуйте її попередню назву.
                            (Ця дія не змінює вулицю на попередню назву)
                        </small>
                    </div>
                </form>

                @if(empty($streetOptions))
                    <div class="alert alert-warning mb-0">
                        Вулиці за пошуковим текстом не знайдено.
                        Перевірте адресу замовлення.
                    </div>
                @else
                    <form
                        method="post"
                        action="{{ route(
                            'admin.ttn-batches.address.street',
                            [$batch, $entry]
                        ) }}"
                    >
                        @csrf

                        <input
                            type="hidden"
                            name="street_search"
                            value="{{ $streetSearch }}"
                        >

                        @foreach($streetOptions as $option)
                            @php
                                $checked =
                                    old(
                                        'street_ref',
                                        $order
                                            ->np_recipient_street_ref
                                    )
                                    === $option['street_ref'];
                            @endphp

                            <div class="custom-control custom-radio mb-2">
                                <input
                                    type="radio"
                                    class="custom-control-input"
                                    id="street-{{ $loop->index }}"
                                    name="street_ref"
                                    value="{{ $option['street_ref'] }}"
                                    {{ $checked ? 'checked' : '' }}
                                >

                                <label
                                    class="custom-control-label"
                                    for="street-{{ $loop->index }}"
                                >
                                    <strong>
                                        {{ $option['name'] }}
                                    </strong>

                                    @if($option['type'])
                                        — {{ $option['type'] }}
                                    @endif
                                </label>
                            </div>
                        @endforeach

                        <button
                            type="submit"
                            class="btn btn-success mt-3"
                        >
                            Зберегти адресу та повторити ТТН
                        </button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    @if(
        !empty($order->np_recipient_settlement_ref)
        || !empty($order->np_recipient_street_ref)
    )
        <form
            method="post"
            action="{{ route(
                'admin.ttn-batches.address.reset',
                [$batch, $entry]
            ) }}"
            onsubmit="
                return confirm(
                    'Скинути збережений ручний вибір адреси?'
                );
            "
        >
            @csrf

            <button
                type="submit"
                class="btn btn-outline-danger"
            >
                Скинути ручний вибір
            </button>
        </form>
    @endif
</div>
@endsection