@extends('admin.layout')

@section('title', 'Orders')
@section('header', 'Orders')

@section('content')
<form class="card card-body mb-3" method="get" action="{{ route('admin.orders.index') }}">
    <div class="form-row">
        <div class="col-md-4 mb-2">
            <label class="small mb-1">Пошук (guid / Телефон / ТТН / Клієнт)</label>
            <input name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Search...">
        </div>

        <div class="col-md-2 mb-2">
            <label class="small mb-1">Статус</label>
            <select name="status" class="form-control">
                <option value="">Всі</option>
                @foreach(['pending','created','updated','shipped','canceled'] as $s)
                    <option value="{{ $s }}" @if($filters['status']===$s) selected @endif>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-2 mb-2">
            <label class="small mb-1">Від</label>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control" placeholder="DD-MM-YYYY">
        </div>

        <div class="col-md-2 mb-2">
            <label class="small mb-1">До</label>
            <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control" placeholder="DD-MM-YYYY">
        </div>

        <div class="col-md-2 mb-2">
            <label class="small mb-1">Замовлень на сторінці</label>
            <select name="per_page" class="form-control">
                @foreach([10,20,50,100] as $pp)
                    <option value="{{ $pp }}" @if($filters['per_page']===$pp) selected @endif>{{ $pp }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="mt-2">
        <button class="btn btn-primary">Застосувати</button>
        <a class="btn btn-link" href="{{ route('admin.orders.index') }}">Скасувати</a>
    </div>
</form>

<style>
    .tr-danger .btn-outline-primary
    {
        color: #fff;
        border-color: #fff;
    }
    .tr-danger
    {
        color: #fff;
        background: #f57c73;
    }
</style>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="thead-light">
            <tr>
                <th>ID</th>
                <th>GUID</th>
                <th>Клієнт</th>
                <th>Телефон</th>
                <th>Статус</th>
                <th>Контроль оплати</th>
                <th>ТТН</th>
                <th>Created</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($orders as $o)

                @php
                $statusClass = [
                'pending'  => 'badge-warning',
                'created'  => 'badge-primary',
                'updated'  => 'badge-info',
                'shipped'  => 'badge-success',
                'canceled' => 'badge-danger',
                ][$o->status] ?? 'badge-secondary';
                @endphp

                <tr class="{{ $o->cash_on_delivery > 0 ? 'tr-danger' : ''}}">
                    <td>{{ $o->id }}</td>
                    <td class="mono">{{ $o->guid }}</td>
                    <td>{{ $o->customer_name }}</td>
                    <td class="mono">{{ $o->delivery_phone }}</td>
                    <td><span class="badge {{ $statusClass }} badge-status">{{ $o->status }}</span></td>
                    <td><span class="mono">{{ $o->cash_on_delivery }}</span></td>
                    <td class="mono">
                        {{ $o->tracking_number }}
                        @if($o->files_exists)
                        <span class="badge badge-danger ml-1" title="Є файл">PDF</span>
                        @endif
                    </td>
                    <td>{{ optional($o->created_at)->format('d-m-Y H:i') }}</td>
                    <td class="text-right">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.orders.show', $o->id) }}">Подробиці</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted p-4">No orders</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="my-4">
    {{ $orders->links() }}
</div>
@endsection
