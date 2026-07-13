@extends('admin.layout')

@section('title', 'Edit reserved')
@section('header', 'Edit reserved quantity')

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
            <div><span class="text-muted">К-сть в рахунку-фактурі:</span>{{ $item->quantity }}</div>
        </div>

        <form method="post" action="{{ route('admin.items.update', $item->id) }}">
            @csrf
            <div class="form-group">
                <label>Зарезервована к-сть</label>
                <input name="reserved_quantity" class="form-control" value="{{ old('reserved_quantity', $item->reserved_quantity) }}" required>
            </div>
            <button class="btn btn-primary">Оновити</button>
        </form>
    </div>
</div>
@endsection
