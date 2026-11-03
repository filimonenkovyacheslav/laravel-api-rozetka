@extends('admin.layout')

@section('title', 'Підтвердження кількості')
@section('header', 'Підтвердження кількості')

@section('content')
<div class="mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.orders.show', $item->order_id) }}">← Back to order</a>
</div>

<div class="card">
    <div class="card-body">
        <div class="mb-3">
            <div><span class="text-muted">Внутрішній код товару постачальника:</span> <span class="mono">{{ $item->supplier_code }}</span></div>
            <div><span class="text-muted">Артикул (RZ):</span> <span class="mono">{{ $item->rz_code }}</span></div>
            <div><span class="text-muted">Посилання (RZ):</span> <span class="mono"><a href="https://rozetka.com.ua/{{ $item->rz_code }}/p{{ $item->rz_code }}/" target= _blank >{{ $item->rz_code }}</a></span></div>
            <div><span class="text-muted">Замовлена к-сть:</span>{{ $item->quantity }}</div>
        </div>

        <form method="post" action="{{ route('admin.items.update', $item->id) }}">
            @csrf
            <div class="form-group">
                <label>Підтверджена к-сть</label>
                <input name="reserved_quantity" class="form-control" value="{{ old('reserved_quantity', $item->reserved_quantity) }}" required>
            </div>
            @if(!empty($order->rozetka_status))
                <div class="small text-muted mb-3">
                    Обрана кількість зберігається локально. Підтвердження в Rozetka виконується
                    кнопкою «Підтвердити всю кількість» у замовленні.
                </div>
            @endif
            <button class="btn btn-primary">Підтвердити</button>
        </form>
    </div>
</div>
@endsection
