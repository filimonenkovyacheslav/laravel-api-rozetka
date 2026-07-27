<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductDimension;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
}