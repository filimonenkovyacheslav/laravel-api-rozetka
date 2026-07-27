@extends('admin.layout')

@section('content')
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h1 class="h3 mb-1">
                    Пакет ТТН №{{ $batch->id }}
                </h1>

                <div class="text-muted">
                    Створено:
                    {{ optional($batch->created_at)->format('d.m.Y H:i') }}
                </div>
            </div>

            <a
                href="{{ route('admin.ttn-batches.index') }}"
                class="btn btn-outline-secondary"
            >
                До списку пакетів
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
                <ul class="mb-0">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="row mb-4">
            <div class="col-md-3 mb-2">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted">Статус</div>
                        <strong>{{ $batch->status }}</strong>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-2">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted">Усього</div>
                        <strong>{{ $batch->total_count }}</strong>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-2">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted">Успішно</div>
                        <strong class="text-success">
                            {{ $batch->success_count }}
                        </strong>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-2">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted">Помилки</div>
                        <strong class="text-danger">
                            {{ $batch->failed_count }}
                        </strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                Excel-звіт
            </div>

            <div class="card-body">
                <form
                    method="post"
                    action="{{ route('admin.ttn-batches.export', $batch) }}"
                    class="row align-items-end"
                >
                    @csrf

                    <div class="col-md-5 mb-2">
                        <label class="form-label">
                            Email отримувача
                        </label>

                        <input
                            type="email"
                            name="recipient_email"
                            class="form-control"
                            value="{{ old(
                                'recipient_email',
                                config('services.order_reports.recipient')
                            ) }}"
                        >
                    </div>

                    <div class="col-md-4 mb-2">
                        <button
                            type="submit"
                            class="btn btn-success"
                            @if($batch->success_count < 1) disabled @endif
                        >
                            Сформувати Excel
                        </button>
                    </div>
                </form>

                @if($batch->success_count < 1)
                    <div class="text-muted mt-2">
                        У пакеті немає успішно сформованих ТТН.
                    </div>
                @endif
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                Формування ТТН
            </div>

            <div class="card-body">
                @if((int) $batch->failed_count > 0)
                    <form
                        method="post"
                        action="{{ route(
                            'admin.ttn-batches.retry-failed',
                            $batch
                        ) }}"
                        class="d-inline"
                    >
                        @csrf

                        <button
                            type="submit"
                            class="btn btn-outline-warning ml-2"
                        >
                            Скинути помилки
                            ({{ (int) $batch->failed_count }})
                        </button>
                    </form>
                @endif
                
                <button
                    type="button"
                    id="process-ttn-batch"
                    class="btn btn-primary"
                    data-url="{{ route(
                        'admin.ttn-batches.process-next',
                        $batch
                    ) }}"
                    @if(
                        !in_array(
                            $batch->status,
                            ['pending', 'processing'],
                            true
                        )
                    )
                        disabled
                    @endif
                >
                    Розпочати формування ТТН
                </button>

                <button
                    type="button"
                    id="delete-ttn-batch"
                    class="btn btn-outline-danger ml-2"
                    data-url="{{ route(
                        'admin.ttn-batches.delete-next',
                        $batch
                    ) }}"
                    @if((int) $batch->success_count === 0)
                        disabled
                    @endif
                >
                    Видалити створені ТТН
                    @if((int) $batch->success_count > 0)
                        ({{ (int) $batch->success_count }})
                    @endif
                </button>

                <div
                    id="delete-ttn-progress"
                    class="mt-3"
                    style="display: none;"
                >
                    <div class="progress">
                        <div
                            id="delete-ttn-progress-bar"
                            class="progress-bar"
                            role="progressbar"
                            style="width: 0%;"
                        >
                            0%
                        </div>
                    </div>

                    <div
                        id="delete-ttn-message"
                        class="mt-2 text-muted"
                    ></div>
                </div>

                <div
                    id="ttn-batch-progress"
                    class="mt-3"
                    style="display: none;"
                >
                    <div class="progress">
                        <div
                            id="ttn-batch-progress-bar"
                            class="progress-bar"
                            role="progressbar"
                            style="width: 0%;"
                        >
                            0%
                        </div>
                    </div>

                    <div
                        id="ttn-batch-message"
                        class="mt-2 text-muted"
                    ></div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                Замовлення пакета
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Замовлення</th>
                            <th>Статус</th>
                            <th>ТТН</th>
                            <th>Спроб</th>
                            <th>Помилка</th>
                            <th>Оброблено</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($batch->entries as $entry)
                            <tr>
                                <td>{{ $entry->id }}</td>

                                <td>
                                    @if($entry->order)
                                        <a
                                            href="{{ route(
                                                'admin.orders.show',
                                                $entry->order
                                            ) }}"
                                        >
                                            №{{ $entry->order->id }}
                                        </a>
                                    @else
                                        Замовлення видалено
                                    @endif
                                </td>

                                <td>
                                    {{ $entry->status }}
                                </td>

                                <td>
                                    {{ $entry->tracking_number ?: '—' }}
                                </td>

                                <td>
                                    {{ $entry->attempts }}
                                </td>

                                <td>
                                    @if($entry->error_message)
                                        <div style="max-width: 450px;">
                                            {{ $entry->error_message }}
                                        </div>
                                    @else
                                        —
                                    @endif

                                    @if(
                                        $entry->status === 'failed'
                                        && $entry->order
                                        && empty($entry->tracking_number)
                                    )
                                        <div class="mt-2">
                                            <a
                                                href="{{ route(
                                                    'admin.ttn-batches.address.show',
                                                    [$batch, $entry]
                                                ) }}"
                                                class="btn btn-sm btn-outline-warning"
                                            >
                                                Уточнити адресу
                                            </a>
                                        </div>
                                    @endif
                                </td>

                                <td>
                                    {{ optional($entry->processed_at)
                                        ->format('d.m.Y H:i:s') ?: '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td
                                    colspan="7"
                                    class="text-center text-muted"
                                >
                                    У пакеті ще немає замовлень.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($batch->exports->isNotEmpty())
            <div class="card">
                <div class="card-header">
                    Сформовані Excel-звіти
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Файл</th>
                                <th>Статус</th>
                                <th>Email</th>
                                <th>Сформовано</th>
                                <th></th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach($batch->exports as $export)
                                <tr>
                                    <td>{{ $export->id }}</td>
                                    <td>{{ $export->file_name }}</td>
                                    <td>{{ $export->status }}</td>
                                    <td>
                                        {{ $export->recipient_email ?: '—' }}
                                    </td>
                                    <td>
                                        {{ optional($export->generated_at)
                                            ->format('d.m.Y H:i:s') ?: '—' }}
                                    </td>

                                    <td class="text-nowrap">
                                        @if(
                                            in_array(
                                                $export->status,
                                                ['generated', 'sent'],
                                                true
                                            )
                                        )
                                        <a
                                            href="{{ route(
                                                'admin.order-exports.download',
                                                [$batch, $export]
                                            ) }}"
                                            class="btn btn-sm btn-outline-primary"
                                        >
                                            Завантажити
                                        </a>

                                        <form
                                            method="POST"
                                            action="{{ route(
                                                'admin.order-exports.destroy',
                                                [
                                                    'batch' => $batch,
                                                    'export' => $export,
                                                ]
                                            ) }}"
                                            class="d-inline"
                                            onsubmit="
                                                return confirm(
                                                    'Видалити Excel-звіт та файл без можливості відновлення?'
                                                );
                                            "
                                        >
                                            @csrf
                                            @method('DELETE')

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                            >
                                                Видалити
                                            </button>
                                        </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var button = document.getElementById(
                'process-ttn-batch'
            );

            if (!button) {
                return;
            }

            var progressContainer = document.getElementById(
                'ttn-batch-progress'
            );

            var progressBar = document.getElementById(
                'ttn-batch-progress-bar'
            );

            var messageElement = document.getElementById(
                'ttn-batch-message'
            );

            var totalCount = {{ (int) $batch->total_count }};

            button.addEventListener('click', async function () {
                if (!confirm(
                    'Розпочати автоматичне формування ТТН?'
                )) {
                    return;
                }

                button.disabled = true;
                progressContainer.style.display = 'block';

                var finished = false;

                while (!finished) {
                    try {
                        var response = await fetch(
                            button.dataset.url,
                            {
                                method: 'POST',

                                credentials: 'same-origin',

                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type':
                                        'application/json',

                                    'X-CSRF-TOKEN':
                                        '{{ csrf_token() }}'
                                },

                                body: JSON.stringify({})
                            }
                        );

                        var result = await response.json();

                        if (!response.ok) {
                            throw new Error(
                                result.message ||
                                'Помилка HTTP ' +
                                response.status
                            );
                        }

                        var batch = result.batch || {};

                        var successCount = parseInt(
                            batch.success_count || 0,
                            10
                        );

                        var failedCount = parseInt(
                            batch.failed_count || 0,
                            10
                        );

                        var processed =
                            successCount + failedCount;

                        var percent = totalCount > 0
                            ? Math.round(
                                processed / totalCount * 100
                            )
                            : 100;

                        progressBar.style.width =
                            percent + '%';

                        progressBar.textContent =
                            percent + '%';

                        messageElement.textContent =
                            'Оброблено: ' +
                            processed +
                            ' із ' +
                            totalCount +
                            '. Успішно: ' +
                            successCount +
                            '. Помилок: ' +
                            failedCount +
                            '. ' +
                            (result.message || '');

                        finished = result.done === true;

                        if (!finished) {
                            await new Promise(function (resolve) {
                                setTimeout(resolve, 700);
                            });
                        }
                    } catch (error) {
                        messageElement.textContent =
                            'Обробку зупинено: ' +
                            error.message;

                        button.disabled = false;

                        return;
                    }
                }

                messageElement.textContent +=
                    ' Обробку пакета завершено.';

                setTimeout(function () {
                    window.location.reload();
                }, 1200);
            });
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var button = document.getElementById(
                'delete-ttn-batch'
            );

            if (!button) {
                return;
            }

            var progress = document.getElementById(
                'delete-ttn-progress'
            );

            var progressBar = document.getElementById(
                'delete-ttn-progress-bar'
            );

            var message = document.getElementById(
                'delete-ttn-message'
            );

            var initialCount = {{ (int) $batch->success_count }};

            button.addEventListener('click', async function () {
                var confirmed = confirm(
                    'Усі створені в цьому пакеті ТТН будуть ' +
                    'видалені в Новій Пошті.\n\n' +
                    'Замовлення будуть повернуті до стану перед ' +
                    'відправленням, а записи пакета — до pending.\n\n' +
                    'Продовжити?'
                );

                if (!confirmed) {
                    return;
                }

                button.disabled = true;
                progress.style.display = 'block';

                var finished = false;

                while (!finished) {
                    try {
                        var response = await fetch(
                            button.dataset.url,
                            {
                                method: 'POST',

                                credentials: 'same-origin',

                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type':
                                        'application/json',

                                    'X-CSRF-TOKEN':
                                        '{{ csrf_token() }}'
                                },

                                body: JSON.stringify({})
                            }
                        );

                        var result = await response.json();

                        if (!response.ok) {
                            throw new Error(
                                result.message ||
                                'Помилка HTTP ' +
                                response.status
                            );
                        }

                        var remaining = parseInt(
                            result.remaining_count || 0,
                            10
                        );

                        var deleted =
                            initialCount - remaining;

                        var percent = initialCount > 0
                            ? Math.round(
                                deleted / initialCount * 100
                            )
                            : 100;

                        progressBar.style.width =
                            percent + '%';

                        progressBar.textContent =
                            percent + '%';

                        message.textContent =
                            'Видалено: ' +
                            deleted +
                            ' із ' +
                            initialCount +
                            '. ' +
                            (result.message || '');

                        finished = result.done === true;

                        if (!finished) {
                            await new Promise(function (resolve) {
                                setTimeout(resolve, 500);
                            });
                        }
                    } catch (error) {
                        message.textContent =
                            'Масове видалення зупинено: ' +
                            error.message;

                        button.disabled = false;

                        return;
                    }
                }

                message.textContent +=
                    ' Пакет готовий до повторного формування ТТН.';

                setTimeout(function () {
                    window.location.reload();
                }, 1200);
            });
        });
    </script>

@endsection