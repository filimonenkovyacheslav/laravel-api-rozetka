<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductDimension;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProductDimensionController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = ProductDimension::query()
            ->with('places')
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $search = trim($request->query('q'));

            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('rz_code', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%');
            });
        }

        $products = $query
            ->paginate(50)
            ->appends($request->query());

        return view('admin.product_dimensions.index', [
            'products' => $products,
            'q' => $request->query('q', ''),
        ]);
    }

    /**
     * Создание нового товара с первым упаковочным местом.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'rz_code' => [
                'required',
                'string',
                'max:100',
                'unique:product_dimensions,rz_code',
            ],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'weight' => [
                'required',
                'numeric',
                'min:0.001',
            ],
            'length' => [
                'required',
                'numeric',
                'min:0.01',
            ],
            'width' => [
                'required',
                'numeric',
                'min:0.01',
            ],
            'height' => [
                'required',
                'numeric',
                'min:0.01',
            ],
        ]);

        DB::transaction(function () use ($data) {
            $product = ProductDimension::create([
                'rz_code' => trim($data['rz_code']),
                'name' => trim($data['name']),
                // Эти поля остаются как сводные.
                'weight' => $data['weight'],
                'length' => $data['length'],
                'width' => $data['width'],
                'height' => $data['height'],
            ]);

            $product->places()->create([
                'place_number' => 1,
                'weight' => $data['weight'],
                'length' => $data['length'],
                'width' => $data['width'],
                'height' => $data['height'],
            ]);
        });

        return redirect()
            ->route('admin.product-dimensions.index')
            ->with('ok', 'Товар та перше місце успішно додано.');
    }

    /**
     * Сохранение всей таблицы и всех упаковочных мест.
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'products' => [
                'required',
                'array',
                'min:1',
            ],

            'products.*.id' => [
                'required',
                'integer',
                'exists:product_dimensions,id',
            ],

            'products.*.rz_code' => [
                'required',
                'string',
                'max:100',
            ],

            'products.*.name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'products.*.places' => [
                'required',
                'array',
                'min:1',
            ],

            'products.*.places.*.id' => [
                'nullable',
                'integer',
                'exists:product_dimension_places,id',
            ],

            'products.*.places.*.weight' => [
                'required',
                'numeric',
                'min:0.001',
            ],

            'products.*.places.*.length' => [
                'required',
                'numeric',
                'min:0.01',
            ],

            'products.*.places.*.width' => [
                'required',
                'numeric',
                'min:0.01',
            ],

            'products.*.places.*.height' => [
                'required',
                'numeric',
                'min:0.01',
            ],
        ]);

        $rows = array_values($validated['products']);

        $this->validateProductCodes($rows);

        DB::transaction(function () use ($rows) {
            foreach ($rows as $productIndex => $row) {
                $product = ProductDimension::query()
                    ->whereKey($row['id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $places = array_values($row['places']);

                $existingPlaceIds = $product
                    ->places()
                    ->pluck('id')
                    ->map(function ($id) {
                        return (int) $id;
                    })
                    ->all();

                $submittedExistingIds = [];

                foreach ($places as $placeIndex => $placeData) {
                    $attributes = [
                        'place_number' => $placeIndex + 1,
                        'weight' => $placeData['weight'],
                        'length' => $placeData['length'],
                        'width' => $placeData['width'],
                        'height' => $placeData['height'],
                    ];

                    if (!empty($placeData['id'])) {
                        $placeId = (int) $placeData['id'];

                        if (!in_array(
                            $placeId,
                            $existingPlaceIds,
                            true
                        )) {
                            throw ValidationException::withMessages([
                                "products.{$productIndex}.places" =>
                                    'Одне з місць не належить цьому товару.',
                            ]);
                        }

                        $product
                            ->places()
                            ->whereKey($placeId)
                            ->update($attributes);

                        $submittedExistingIds[] = $placeId;
                    } else {
                        $product->places()->create($attributes);
                    }
                }

                /*
                 * Удаляем старые места, которые менеджер
                 * убрал со страницы.
                 */
                $placeIdsToDelete = array_diff(
                    $existingPlaceIds,
                    $submittedExistingIds
                );

                if (!empty($placeIdsToDelete)) {
                    $product
                        ->places()
                        ->whereIn('id', $placeIdsToDelete)
                        ->delete();
                }

                /*
                 * Сводные поля основной таблицы:
                 *
                 * weight — общий фактический вес;
                 * length/width/height — максимальные размеры мест.
                 *
                 * Для создания ТТН всё равно нужно использовать
                 * product_dimension_places.
                 */
                $product->update([
                    'rz_code' => trim($row['rz_code']),
                    'name' => isset($row['name'])
                        ? trim($row['name'])
                        : null,
                    'weight' => collect($places)->sum('weight'),
                    'length' => collect($places)->max('length'),
                    'width' => collect($places)->max('width'),
                    'height' => collect($places)->max('height'),
                ]);
            }
        });

        return redirect()
            ->route(
                'admin.product-dimensions.index',
                array_filter([
                    'q' => $request->query('q'),
                    'page' => $request->query('page'),
                ])
            )
            ->with(
                'ok',
                'Товари та упаковочні місця успішно збережено.'
            );
    }

    public function destroy(ProductDimension $productDimension)
    {
        $productDimension->delete();

        return redirect()
            ->route('admin.product-dimensions.index')
            ->with('ok', 'Товар видалено.');
    }

    /**
     * Проверяет уникальность артикулов до начала обновления.
     */
    private function validateProductCodes(array $rows)
    {
        $submittedCodes = [];

        foreach ($rows as $index => $row) {
            $code = trim($row['rz_code']);

            if (in_array($code, $submittedCodes, true)) {
                throw ValidationException::withMessages([
                    "products.{$index}.rz_code" =>
                        'Артикул повторюється у таблиці.',
                ]);
            }

            $submittedCodes[] = $code;

            $alreadyExists = ProductDimension::query()
                ->where('rz_code', $code)
                ->where('id', '<>', $row['id'])
                ->exists();

            if ($alreadyExists) {
                throw ValidationException::withMessages([
                    "products.{$index}.rz_code" =>
                        'Товар з таким артикулом уже існує.',
                ]);
            }
        }
    }

    public function importCsv(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'max:10240',
            ],
        ], [
            'csv_file.required' =>
                'Оберіть CSV-файл для імпорту.',

            'csv_file.file' =>
                'Не вдалося прочитати завантажений файл.',

            'csv_file.max' =>
                'Розмір CSV-файлу не повинен перевищувати 10 МБ.',
        ]);

        $file = $request->file('csv_file');

        $extension = strtolower(
            (string) $file->getClientOriginalExtension()
        );

        if ($extension !== 'csv') {
            return back()->with(
                'error',
                'Потрібно завантажити файл у форматі CSV.'
            );
        }

        $handle = fopen(
            $file->getRealPath(),
            'rb'
        );

        if ($handle === false) {
            return back()->with(
                'error',
                'Не вдалося відкрити CSV-файл.'
            );
        }

        try {
            $header = fgetcsv(
                $handle,
                0,
                ','
            );

            if (!is_array($header)) {
                throw new RuntimeException(
                    'CSV-файл не містить заголовка.'
                );
            }

            $header = array_map(function ($value) {
                return trim(
                    preg_replace(
                        '/^\xEF\xBB\xBF/',
                        '',
                        (string) $value
                    )
                );
            }, $header);

            $expectedHeader =
                $this->productDimensionCsvHeader();

            if ($header !== $expectedHeader) {
                throw new RuntimeException(
                    'Структура CSV-файлу не відповідає шаблону. ' .
                    'Очікується 26 колонок від "Артикул Розетки" ' .
                    'до "Висота 6, см".'
                );
            }

            $records = [];
            $seenArticles = [];
            $conflicts = [];

            $lineNumber = 1;

            while (
                ($row = fgetcsv($handle, 0, ',')) !== false
            ) {
                $lineNumber++;

                /*
                 * Пропускаем полностью пустые строки.
                 */
                $hasValues = false;

                foreach ($row as $value) {
                    if (trim((string) $value) !== '') {
                        $hasValues = true;
                        break;
                    }
                }

                if (!$hasValues) {
                    continue;
                }

                if (count($row) !== count($expectedHeader)) {
                    throw new RuntimeException(
                        'Рядок ' . $lineNumber .
                        ' містить ' . count($row) .
                        ' колонок замість ' .
                        count($expectedHeader) . '.'
                    );
                }

                $record =
                    $this->parseProductDimensionCsvRow(
                        $row,
                        $lineNumber
                    );

                $article = $record['rz_code'];

                $signature = json_encode(
                    $record,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                );

                if (isset($seenArticles[$article])) {
                    /*
                     * Полностью одинаковую повторную строку
                     * можно просто проигнорировать.
                     */
                    if (
                        $seenArticles[$article]['signature']
                        === $signature
                    ) {
                        continue;
                    }

                    $conflicts[] =
                        'Артикул ' . $article .
                        ': рядки ' .
                        $seenArticles[$article]['line'] .
                        ' та ' . $lineNumber .
                        ' містять різні товари.';

                    continue;
                }

                $seenArticles[$article] = [
                    'line' => $lineNumber,
                    'signature' => $signature,
                ];

                $records[] = $record;
            }
        } catch (Throwable $e) {
            fclose($handle);

            report($e);

            return back()->with(
                'error',
                'Помилка перевірки CSV: ' .
                $e->getMessage()
            );
        }

        fclose($handle);

        if (!empty($conflicts)) {
            return back()->with(
                'error',
                'Імпорт зупинено через дублікати: ' .
                implode(' ', $conflicts)
            );
        }

        if (empty($records)) {
            return back()->with(
                'error',
                'CSV-файл не містить товарів для імпорту.'
            );
        }

        $createdCount = 0;
        $updatedCount = 0;
        $placesCount = 0;

        try {
            DB::transaction(function () use (
                $records,
                &$createdCount,
                &$updatedCount,
                &$placesCount
            ) {
                foreach ($records as $record) {
                    $firstPlace = $record['places'][0];

                    $product = ProductDimension::query()
                        ->where(
                            'rz_code',
                            $record['rz_code']
                        )
                        ->first();

                    if ($product) {
                        $updatedCount++;
                    } else {
                        $product = new ProductDimension();
                        $createdCount++;
                    }

                    /*
                     * Первое грузовое место хранится
                     * в основной таблице product_dimensions.
                     */
                    $product->fill([
                        'rz_code' =>
                            $record['rz_code'],

                        'name' =>
                            $record['name'],

                        'weight' =>
                            $firstPlace['weight'],

                        'length' =>
                            $firstPlace['length'],

                        'width' =>
                            $firstPlace['width'],

                        'height' =>
                            $firstPlace['height'],
                    ]);

                    $product->save();

                    /*
                     * При повторном импорте полностью заменяем
                     * старый список дополнительных мест.
                     */
                    $product->places()->delete();

                    /*
                     * Все грузовые места, включая место №1,
                     * хранятся в product_dimension_places.
                     *
                     * Основные поля product_dimensions также оставляем
                     * заполненными данными первого места для совместимости
                     * со старым кодом.
                     */
                    foreach ($record['places'] as $place) {
                        $product->places()->create([
                            'place_number' =>
                                $place['place_number'],

                            'weight' =>
                                $place['weight'],

                            'length' =>
                                $place['length'],

                            'width' =>
                                $place['width'],

                            'height' =>
                                $place['height'],
                        ]);

                        $placesCount++;
                    }
                }
            });
        } catch (Throwable $e) {
            report($e);

            return back()->with(
                'error',
                'Не вдалося імпортувати товари: ' .
                $e->getMessage()
            );
        }

        return back()->with(
            'success',
            'Імпорт завершено. Створено товарів: ' .
            $createdCount .
            ', оновлено: ' .
            $updatedCount .
            ', додаткових місць: ' .
            $placesCount . '.'
        );
    }

    private function productDimensionCsvHeader()
    {
        return [
            'Артикул Розетки',
            'Назва товару',

            'Вага, кг',
            'Довжина, см',
            'Ширина, см',
            'Висота, см',

            'Вага 2, кг',
            'Довжина 2, см',
            'Ширина 2, см',
            'Висота 2, см',

            'Вага 3, кг',
            'Довжина 3, см',
            'Ширина 3, см',
            'Висота 3, см',

            'Вага 4, кг',
            'Довжина 4, см',
            'Ширина 4, см',
            'Висота 4, см',

            'Вага 5, кг',
            'Довжина 5, см',
            'Ширина 5, см',
            'Висота 5, см',

            'Вага 6, кг',
            'Довжина 6, см',
            'Ширина 6, см',
            'Висота 6, см',
        ];
    }

    private function parseProductDimensionCsvRow(
        array $row,
        $lineNumber
    ) {
        $article = trim(
            preg_replace(
                '/^\xEF\xBB\xBF/',
                '',
                (string) $row[0]
            )
        );

        $name = trim(
            (string) $row[1]
        );

        if ($article === '') {
            throw new RuntimeException(
                'У рядку ' . $lineNumber .
                ' не вказано артикул Розетки.'
            );
        }

        if ($name === '') {
            throw new RuntimeException(
                'У рядку ' . $lineNumber .
                ' не вказано назву товару.'
            );
        }

        $places = [];

        for ($placeNumber = 1; $placeNumber <= 6; $placeNumber++) {
            $offset = 2 + (($placeNumber - 1) * 4);

            $place = $this->parseCsvPlace(
                [
                    $row[$offset],
                    $row[$offset + 1],
                    $row[$offset + 2],
                    $row[$offset + 3],
                ],
                $lineNumber,
                $placeNumber
            );

            if ($place !== null) {
                $places[] = $place;
            }
        }

        if (empty($places)) {
            throw new RuntimeException(
                'У рядку ' . $lineNumber .
                ' не вказано жодного вантажного місця.'
            );
        }

        /*
         * Запрещаем пропуски:
         * нельзя заполнить место 3, оставив пустым место 2.
         */
        foreach ($places as $index => $place) {
            $expectedNumber = $index + 1;

            if (
                (int) $place['place_number']
                !== $expectedNumber
            ) {
                throw new RuntimeException(
                    'У рядку ' . $lineNumber .
                    ' порушено послідовність вантажних місць.'
                );
            }
        }

        return [
            'rz_code' => $article,
            'name' => $name,
            'places' => $places,
        ];
    }

    private function parseCsvPlace(
        array $values,
        $lineNumber,
        $placeNumber
    ) {
        $nonEmptyCount = 0;

        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                $nonEmptyCount++;
            }
        }

        /*
         * Полностью пустое дополнительное место.
         */
        if ($nonEmptyCount === 0) {
            return null;
        }

        /*
         * Если заполнена только часть габаритов —
         * считаем строку ошибочной.
         */
        if ($nonEmptyCount !== 4) {
            throw new RuntimeException(
                'У рядку ' . $lineNumber .
                ' вантажне місце №' . $placeNumber .
                ' заповнено не повністю.'
            );
        }

        return [
            'place_number' => $placeNumber,

            'weight' => $this->parseCsvPositiveNumber(
                $values[0],
                $lineNumber,
                'Вага місця №' . $placeNumber
            ),

            'length' => $this->parseCsvPositiveNumber(
                $values[1],
                $lineNumber,
                'Довжина місця №' . $placeNumber
            ),

            'width' => $this->parseCsvPositiveNumber(
                $values[2],
                $lineNumber,
                'Ширина місця №' . $placeNumber
            ),

            'height' => $this->parseCsvPositiveNumber(
                $values[3],
                $lineNumber,
                'Висота місця №' . $placeNumber
            ),
        ];
    }

    private function parseCsvPositiveNumber(
        $value,
        $lineNumber,
        $fieldName
    ) {
        $normalized = trim(
            (string) $value
        );

        $normalized = str_replace(
            [
                "\xC2\xA0",
                ' ',
                ',',
            ],
            [
                '',
                '',
                '.',
            ],
            $normalized
        );

        if (
            $normalized === ''
            || !is_numeric($normalized)
        ) {
            throw new RuntimeException(
                'У рядку ' . $lineNumber .
                ' поле "' . $fieldName .
                '" містить некоректне число.'
            );
        }

        $number = (float) $normalized;

        if ($number <= 0) {
            throw new RuntimeException(
                'У рядку ' . $lineNumber .
                ' поле "' . $fieldName .
                '" повинно бути більше нуля.'
            );
        }

        return round(
            $number,
            3
        );
    }
}