<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">

    <title>
        Excel-звіт ТТН
    </title>
</head>

<body>
    <p>
        Добрий день!
    </p>

    <p>
        Сформовано Excel-звіт для пакета ТТН
        <strong>№{{ $batch->id }}</strong>.
    </p>

    <p>
        Кількість замовлень у звіті:
        <strong>{{ $ordersCount }}</strong>.
    </p>

    <p>
        Назва файлу:
        <strong>{{ $export->file_name }}</strong>.
    </p>

    <p>
        Excel-файл додано до цього листа.
    </p>
</body>
</html>