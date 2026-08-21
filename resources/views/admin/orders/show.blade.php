@extends('admin.layout')

@section('title', 'Order '.$order->id)
@section('header', 'Order #'.$order->id)

@section('content')
<div class="mb-3 d-flex">
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.orders.index') }}">← Back</a>
    <div class="ml-auto">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.orders.edit', $order->id) }}">Подробиці</a>
    </div>
</div>

@php
  $statusClass = [
    'pending'  => 'badge-warning',
    'created'  => 'badge-primary',
    'updated'  => 'badge-info',
    'shipped'  => 'badge-success',
    'canceled' => 'badge-danger',
  ][$order->status] ?? 'badge-secondary';
@endphp

<div class="card mb-3">
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <div><span class="text-muted">GUID:</span> <span class="mono">{{ $order->guid }}</span></div>
                <div><span class="text-muted">Статус:</span> <span class="badge {{ $statusClass }}">{{ $order->status }}</span></div>
                <div><span class="text-muted">Клієнт:</span> {{ $order->customer_name }}</div>
                <div><span class="text-muted">Телефон:</span> <span class="mono">{{ $order->delivery_phone }}</span></div>
                <div><span class="text-muted">Місто:</span> {{ $order->delivery_city }}</div>
                <div><span class="text-muted">Вулиця / Відділення:</span> {{ $order->delivery_street }}</div>
                <div><span class="text-muted">Служба доставки:</span> {{ $order->delivery_company }}</div>
                <div><span class="text-muted">Delivery type:</span> {{ $order->delivery_type }}</div>
                <div><span class="text-muted">ID відділення:</span> <span class="mono">{{ $order->delivery_address_id }}</span></div>
                <div><span class="text-danger">Контроль оплати:</span> <span class="text-danger">{{ $order->cash_on_delivery }}</span></div>
            </div>

            <div class="col-md-6">
                <div><span class="text-muted">ТТН:</span> <span class="mono">{{ $order->tracking_number }}</span></div>
                <div><span class="text-muted">Оплата при отриманні:</span> {{ $order->cash_on_delivery }}</div>
                <div><span class="text-muted">Created:</span> {{ optional($order->created_at)->format('d-m-Y H:i') }}</div>
                <div><span class="text-muted">Updated:</span> {{ optional($order->updated_at)->format('d-m-Y H:i') }}</div>
                <div class="mt-2">
                    <div class="text-muted">Коментар</div>
                    <div class="border rounded p-2">{{ $order->comment }}</div>
                </div>
            </div>            
        </div>

        <hr>
        <div class="d-flex align-items-center">
            <div style="width: 400px; margin-left: 50px;">
                можливі варіанти статусу:<br>
                "created" - статус при опрацюванні замовлення,<br>
                "updated" - статус при опрацюванні редагованого замовлення,<br>
                "shipped" - відвантажене замовлення (є номер ТТН).   
            </div>
        </div>
        <hr>

        <div class="d-flex align-items-center">
            <form method="post" action="{{ route('admin.orders.cancel', $order->id) }}" class="mr-2">
                @csrf
                <button class="btn btn-danger" @if($order->status === 'canceled') disabled @endif onclick="return confirm('Ви впевнені що хочете скасувати замовлення?')">
                    Скасувати замовлення
                </button>
            </form>
           
            <!-- <form method="post" action="{{ route('admin.orders.ship', $order->id) }}" class="form-inline">
                @csrf
                <label class="mr-2 mb-0">ТТН</label>
                <input name="tracking_number" class="form-control mr-2" style="min-width: 260px" value="{{ old('tracking_number', $order->tracking_number) }}" @if($order->status === 'canceled') disabled @endif>
                <button class="btn btn-success" @if($order->status === 'canceled') disabled @endif onclick="return confirm('Ви впевнені що хочете позначити як відвантажене?')">
                    Відвантажити
                </button>
            </form> -->

            @if(
                !empty($order->tracking_number) &&
                !empty($order->np_document_ref)
            )
                <div style="width: 400px; margin-left: 50px;">
                    <label class="mr-2 mb-0">ТТН</label>
                    <label class="mr-2 mb-0">{{ old('tracking_number', $order->tracking_number) }}</label>
                </div>
                
                <form
                    method="post"
                    action="{{ route(
                        'admin.orders.ttn.delete',
                        $order
                    ) }}"
                    class="d-inline"
                    onsubmit="
                        return confirm(
                            'ТТН буде видалено в Новій Пошті. ' +
                            'Дані відправлення в замовленні також будуть очищені. ' +
                            'Продовжити?'
                        );
                    "
                >
                    @csrf

                    <button
                        type="submit"
                        class="btn btn-outline-danger"
                    >
                        Видалити ТТН
                    </button>
                </form>
            @endif
            
        </div>
        @if($order->status === 'canceled')
            <div class="text-danger mt-2">Скасоване замовлення неможливо позначити як відвантажене.</div>
        @endif
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex align-items-center">
        <strong>Товари</strong>
        <span class="text-muted ml-2">(можна редагувати тільки зарезервовану кількість)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="thead-light">
            <tr>
                <th>Внутрішній код товару постачальника</th>
                <th>Артикул (RZ)</th>
                <th>Посилання (RZ)</th>
                <th>К-сть в рахунку-фактурі</th>
                <th>Зарезервована к-сть</th>
                <th>Ціна</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach($order->items as $it)
                <tr>
                    <td class="mono">{{ $it->supplier_code }}</td>
                    <td class="mono">{{ $it->rz_code }}</td>
                    <td class="mono"><a href="https://rozetka.com.ua/{{ $it->rz_code }}/p{{ $it->rz_code }}/" target= _blank >{{ $it->rz_code }}</a></td>
                    <td>{{ $it->quantity }}</td>
                    <td><strong>{{ $it->reserved_quantity }}</strong></td>
                    <td>{{ $it->price }}</td>
                    <td class="text-right">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.items.edit', $it->id) }}">Зарезервувати</a>
                    </td>
                </tr>
            @endforeach
            @if($order->items->count() === 0)
                <tr><td colspan="6" class="text-center text-muted p-4">No items</td></tr>
            @endif
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><strong>Files</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="thead-light">
            <tr>
                <th>Path</th>
                <th>Created</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach($order->files as $f)
                <tr>
                    <td class="mono">{{ $f->path }}</td>
                    <td>{{ optional($f->created_at)->format('d-m-Y H:i') }}</td>
                    <td class="text-right">
                        <a class="btn btn-sm btn-outline-success" href="{{ route('admin.files.download', $f->id) }}">Download</a>
                        <form class="d-inline" method="post" action="{{ route('admin.files.delete', $f->id) }}">
                            @csrf
                            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete file?')">Delete</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            @if($order->files->count() === 0)
                <tr><td colspan="3" class="text-center text-muted p-4">No files</td></tr>
            @endif
            </tbody>
        </table>
    </div>
</div>
@endsection
