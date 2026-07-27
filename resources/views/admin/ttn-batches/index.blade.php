@extends('admin.layout')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex align-items-center mb-3">
        <h1 class="h3 mb-0">
            Пакети масового формування ТТН
        </h1>
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

    <div class="card">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>ID</th>
                        <th>Статус</th>
                        <th>Усього</th>
                        <th>Успішно</th>
                        <th>Помилки</th>
                        <th>Створив</th>
                        <th>Дата</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($batches as $batch)
                        <tr>
                            <td>
                                №{{ $batch->id }}
                            </td>

                            <td>
                                {{ $batch->status }}
                            </td>

                            <td>
                                {{ $batch->total_count }}
                            </td>

                            <td class="text-success">
                                {{ $batch->success_count }}
                            </td>

                            <td class="text-danger">
                                {{ $batch->failed_count }}
                            </td>

                            <td>
                                {{ $batch->created_by ?: '—' }}
                            </td>

                            <td>
                                {{ optional($batch->created_at)
                                    ->format('d.m.Y H:i') }}
                            </td>

                            <td>
                                <a
                                    href="{{ route(
                                        'admin.ttn-batches.show',
                                        $batch
                                    ) }}"
                                    class="btn btn-sm btn-outline-primary"
                                >
                                    Відкрити
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="8"
                                class="text-center text-muted"
                            >
                                Пакетів ще немає.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($batches->hasPages())
            <div class="card-footer">
                {{ $batches->links() }}
            </div>
        @endif
    </div>
</div>
@endsection