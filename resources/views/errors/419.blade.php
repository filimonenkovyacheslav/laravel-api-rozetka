@extends('admin.layout')

@section('title', 'Сесію завершено')
@section('header', 'Сесію завершено')

@section('content')
    <div class="card">
        <div class="card-body text-center py-5">
            <h3 class="mb-3">
                Час сесії завершився
            </h3>

            <p class="text-muted mb-4">
                Сторінка була відкрита занадто довго,
                тому захисний токен форми застарів.
                Оновіть сторінку та повторіть дію.
            </p>

            <a
                href="{{ url()->previous() }}"
                class="btn btn-primary"
            >
                Повернутися та оновити
            </a>

            <a
                href="{{ route('admin.orders.index') }}"
                class="btn btn-outline-secondary ml-2"
            >
                До замовлень
            </a>
        </div>
    </div>
@endsection