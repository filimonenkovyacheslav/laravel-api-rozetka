<?php

namespace App\Services\Rozetka;

use App\Models\Order;
use App\Models\OrderFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RozetkaDocumentFileService
{
    private const MAX_PDF_BYTES = 20 * 1024 * 1024;

    /**
     * Save documentLink as a regular OrderFile so the existing
     * admin file badges, filters, and ZIP download work unchanged.
     *
     * The URL hash makes repeated syncs idempotent, while a changed
     * documentLink creates a new, not-yet-downloaded file.
     *
     * @return bool True if a PDF was newly downloaded.
     */
    public function downloadIfNeeded(Order $order)
    {
        $url = trim((string) $order->document_link);

        if ($url === '') {
            return false;
        }

        $this->assertTrustedUrl($url);

        $guid = trim((string) $order->guid);

        if (!preg_match('/^[a-f0-9-]{36}$/iD', $guid)) {
            throw new RuntimeException('Некоректний GUID замовлення для документа Rozetka.');
        }

        $path = 'orders/' . $guid .
            '/rozetka-document-' . hash('sha256', $url) . '.pdf';

        $disk = Storage::disk('local');

        $file = OrderFile::query()
            ->where('guid', $guid)
            ->where('path', $path)
            ->first();

        if ($file && $disk->exists($path)) {
            return false;
        }

        // External URLs are taken only from Rozetka API.
        // Never follow redirects or allow arbitrary hosts (SSRF).
        $response = Http::timeout(25)
            ->withOptions(['allow_redirects' => false])
            ->get($url);

        if ($response->status() !== 200) {
            throw new RuntimeException(
                'PDF Rozetka недоступний (HTTP ' . $response->status() . ').'
            );
        }

        $pdf = $response->body();
        $size = strlen($pdf);

        if (
            $size < 5 ||
            $size > self::MAX_PDF_BYTES ||
            substr($pdf, 0, 5) !== '%PDF-'
        ) {
            throw new RuntimeException(
                'Rozetka повернула некоректний PDF або файл понад 20 МБ.'
            );
        }

        if (!$disk->put($path, $pdf)) {
            throw new RuntimeException(
                'Не вдалося зберегти PDF Rozetka: ' . $path
            );
        }

        try {
            if (!$file) {
                OrderFile::firstOrCreate([
                    'guid' => $guid,
                    'path' => $path,
                ]);
            } else {
                // A deleted physical file was restored; include it
                // in the next "new files" ZIP again.
                $file->downloaded_at = null;
                $file->downloaded_by = null;
                $file->save();
            }
        } catch (\Throwable $e) {
            // A PDF without an OrderFile row cannot be shown in admin.
            // Retrying the sync will repair this situation.
            throw $e;
        }

        return true;
    }

    private function assertTrustedUrl($url)
    {
        $parts = parse_url($url);

        if (
            !is_array($parts) ||
            strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
            !preg_match(
                '/^content[0-9]*\.rozetka\.com\.ua$/D',
                strtolower((string) ($parts['host'] ?? ''))
            ) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['port'])
        ) {
            throw new RuntimeException(
                'Недозволене посилання на PDF Rozetka.'
            );
        }
    }
}
