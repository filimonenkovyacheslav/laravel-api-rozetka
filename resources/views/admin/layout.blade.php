<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding-top: 16px; }
        .badge-status { font-size: 90%; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
        nav svg { width: 16px !important; height: 16px !important; }
        nav > div { margin: 24px 0; }
    </style>
</head>
<body>
<div class="container">
    <div class="d-flex align-items-center mb-3">
        <h3 class="mb-0 mr-3">@yield('header', 'Admin')</h3>
        <div class="ml-auto">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.orders.index') }}">Orders</a>
        </div>
    </div>

    @if(session('ok'))
        <div class="alert alert-success">{{ session('ok') }}</div>
    @endif
    @if(session('err'))
        <div class="alert alert-danger">{{ session('err') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <div class="font-weight-bold mb-2">Validation errors:</div>
            <ul class="mb-0">
                @foreach($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
