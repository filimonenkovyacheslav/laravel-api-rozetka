@extends('admin.layout')

@section('title', 'Edit Order '.$order->id)
@section('header', 'Edit Order #'.$order->id)

@section('content')
<div class="mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.orders.show', $order->id) }}">← Back to order</a>
</div>

<form class="card" method="post" action="{{ route('admin.orders.update', $order->id) }}">
    @csrf
    <div class="card-body">
        <div class="form-row">
            <div class="col-md-4 mb-3">
                <label>Статус</label>
                <select name="status" class="form-control" required>
                    @foreach(['pending','created','updated','shipped','canceled'] as $s)
                        <option value="{{ $s }}" @if(old('status', $order->status)===$s) selected @endif>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label>Клієнт</label>
                <input name="customer_name" class="form-control" value="{{ old('customer_name', $order->customer_name) }}">
            </div>

            <div class="col-md-4 mb-3">
                <label>Телефон</label>
                <input name="delivery_phone" class="form-control" value="{{ old('delivery_phone', $order->delivery_phone) }}">
            </div>

            <div class="col-md-4 mb-3">
                <label>Delivery type</label>
                <input name="delivery_type" class="form-control" value="{{ old('delivery_type', $order->delivery_type) }}">
            </div>

            <div class="col-md-4 mb-3">
                <label>ID відділення</label>
                <input name="delivery_address_id" class="form-control" value="{{ old('delivery_address_id', $order->delivery_address_id) }}">
            </div>

            <div class="col-md-4 mb-3">
                <label>Служба доставки</label>
                <input name="delivery_company" class="form-control" value="{{ old('delivery_company', $order->delivery_company) }}">
            </div>

            <div class="col-md-4 mb-3">
                <label>Місто</label>
                <input name="delivery_city" class="form-control" value="{{ old('delivery_city', $order->delivery_city) }}">
            </div>

            <div class="col-md-8 mb-3">
                <label>Вулиця</label>
                <input name="delivery_street" class="form-control" value="{{ old('delivery_street', $order->delivery_street) }}">
            </div>
        </div>

        <div class="d-flex">
            <button class="btn btn-primary">Оновити</button>
            <a class="btn btn-link" href="{{ route('admin.orders.show', $order->id) }}">Скасувати</a>
        </div>

        <div class="text-muted mt-3">
            GUID: <span class="mono">{{ $order->guid }}</span>
        </div>
    </div>
</form>
@endsection
