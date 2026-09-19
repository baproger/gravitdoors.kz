<?php

declare(strict_types=1);

namespace App\Support\Uploads;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use GdImage;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Сжимает всё, что загружают в систему: чеки, договоры, документы, аватары.
 *
 * Зачем: диск на хостинге 15 ГБ, а фото чека с телефона весит 4–8 МБ. Тысяча
 * сделок с двумя чеками — уже 10 ГБ. После сжатия тот же чек — 200–400 КБ,
 * и на нём всё читается: сторона до 1600 px, JPEG 82 %.
 *
 * Подключается один раз для всех полей загрузки (`configure()` в
 * AppServiceProvider), поэтому новое поле сжимается само, без правок.
 *
 * Что делает:
 *  - JPEG / PNG / WebP: разворот по EXIF, уменьшение до `max_side`, JPEG;
 *    картинка с прозрачностью — WebP, чтобы не залить фон чёрным;
 *  - PDF: пересборка через Ghostscript, если он есть на сервере (на VPS —
 *    да, на виртуальном хостинге обычно нет); берётся, только если стало
 *    заметно меньше;
 *  - остальное (Word, скриншоты меньше лимита) — без изменений.
 *
 * Слишком большие снимки (по памяти) не трогаются, а не роняют запрос:
 * лучше сохранить оригинал, чем потерять чек.
 */
final class UploadCompressor
{
    /**
     * Глобальная настройка поля загрузки Filament: уменьшение ещё в браузере
     * (быстрее грузится с телефона) и сжатие на сервере при сохранении.
     */
    public static function configure(FileUpload $field): void
    {
        $maxSide = (string) config('gravit.uploads.max_side', 1600);

        $field
            ->automaticallyResizeImagesMode('contain')
            ->automaticallyResizeImagesToWidth($maxSide)
            ->automaticallyResizeImagesToHeight($maxSide)
            ->automaticallyUpscaleImagesWhenResizing(false)
            ->saveUploadedFileUsing(static function (BaseFileUpload $component, TemporaryUploadedFile $file): ?string {
                return app(self::class)->save($component, $file);
            });
    }

    /**
     * Сохранить загруженный файл на диск поля, сжав его по дороге.
     * Если сжимать нечего или не получилось — как сохранил бы сам Filament.
     */
    public function save(BaseFileUpload $component, TemporaryUploadedFile $file): ?string
    {
        try {
            if (! $file->exists()) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $source = $file->getRealPath();

        if (! is_file($source)) {
            return $component->saveUploadedFile($file);
        }

        $compressed = $this->compress($source, $file->getMimeType());

        if ($compressed === null) {
            return $component->saveUploadedFile($file);
        }

        $name = $component->getUploadedFileNameForStorage($file);
        $name = preg_replace('/\.[A-Za-z0-9]+$/', '', $name).'.'.$compressed['extension'];
        $path = trim($component->getDirectory().'/'.$name, '/');

        $stream = fopen($compressed['path'], 'rb');
        $component->getDisk()->put($path, $stream, $component->getVisibility() === 'public' ? 'public' : []);

        if (is_resource($stream)) {
            fclose($stream);
        }

        @unlink($compressed['path']);

        return $path;
    }

    /**
     * Сжать файл во временный. Возвращает путь и расширение результата или
     * null, если файл лучше оставить как есть.
     *
     * @return array{path: string, extension: string}|null
     */
    public function compress(string $path, ?string $mime = null): ?array
    {
        $mime ??= (string) File::mimeType($path);

        return match (true) {
            in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) => $this->compressImage($path),
            $mime === 'application/pdf' => $this->compressPdf($path),
            default => null,
        };
    }

    /** @return array{path: string, extension: string}|null */
    private function compressImage(string $path): ?array
    {
        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        [$width, $height] = $info;

        // Память: GD держит 4 байта на пиксель, плюс копия для уменьшения.
        // 40-мегапиксельный снимок на memory_limit 128M уронил бы запрос.
        if (! $this->fitsInMemory($width * $height * 5)) {
            return null;
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if (! $image instanceof GdImage) {
            return null;
        }

        $image = $this->applyExifOrientation($image, $path, $info['mime']);

        $maxSide = (int) config('gravit.uploads.max_side', 1600);
        $quality = (int) config('gravit.uploads.quality', 82);
        $longest = max(imagesx($image), imagesy($image));
        $resized = false;

        if ($longest > $maxSide) {
            // imagecopyresampled, а не imagescale: у imagescale режимы
            // интерполяции зависят от сборки GD и молча возвращают false.
            $scale = $maxSide / $longest;
            $newW = max(1, (int) round(imagesx($image) * $scale));
            $newH = max(1, (int) round(imagesy($image) * $scale));
            $scaled = imagecreatetruecolor($newW, $newH);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);

            if (imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newW, $newH, imagesx($image), imagesy($image))) {
                imagedestroy($image);
                $image = $scaled;
                $resized = true;
            } else {
                imagedestroy($scaled);
            }
        }

        $hasAlpha = $info['mime'] !== 'image/jpeg' && $this->hasTransparency($image);
        $out = tempnam(sys_get_temp_dir(), 'gravit-img-');

        if ($hasAlpha) {
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $ok = function_exists('imagewebp') && imagewebp($image, $out, $quality);
            $extension = 'webp';
        } else {
            // Прозрачности нет — JPEG: у фото чека он в 3–5 раз меньше PNG.
            $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            imagedestroy($image);
            $image = $canvas;
            imageinterlace($image, true);
            $ok = imagejpeg($image, $out, $quality);
            $extension = 'jpg';
        }

        imagedestroy($image);

        // Не стало меньше и не уменьшали — оригинал лучше.
        if (! $ok || (! $resized && filesize($out) >= filesize($path))) {
            @unlink($out);

            return null;
        }

        return ['path' => $out, 'extension' => $extension];
    }

    /**
     * PDF через Ghostscript: /ebook пережимает вложенные фото до 150 dpi —
     * сканы договоров худеют в 3–10 раз, текст остаётся текстом.
     *
     * @return array{path: string, extension: string}|null
     */
    private function compressPdf(string $path): ?array
    {
        if (! config('gravit.uploads.pdf', true) || ! function_exists('exec')) {
            return null;
        }

        $gs = $this->ghostscript();

        if ($gs === null) {
            return null;
        }

        $out = tempnam(sys_get_temp_dir(), 'gravit-pdf-').'.pdf';
        $cmd = sprintf(
            '%s -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -dCompatibilityLevel=1.5 -dPDFSETTINGS=/ebook -sOutputFile=%s %s 2>/dev/null',
            escapeshellcmd($gs),
            escapeshellarg($out),
            escapeshellarg($path),
        );

        exec($cmd, $output, $code);

        // Берём результат только если он заметно меньше и это вообще PDF.
        if ($code !== 0 || ! is_file($out) || filesize($out) < 100
            || filesize($out) > filesize($path) * 0.9
            || ! str_starts_with((string) file_get_contents($out, false, null, 0, 5), '%PDF-')) {
            @unlink($out);

            return null;
        }

        return ['path' => $out, 'extension' => 'pdf'];
    }

    private function ghostscript(): ?string
    {
        static $found = false;

        if ($found !== false) {
            return $found;
        }

        $configured = config('gravit.uploads.ghostscript');

        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $found = $configured;
        }

        $path = trim((string) @shell_exec('command -v gs 2>/dev/null'));

        return $found = ($path !== '' ? $path : null);
    }

    private function applyExifOrientation(GdImage $image, string $path, ?string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated instanceof GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    /** Есть ли хоть один не полностью непрозрачный пиксель (проверяется сеткой, не каждый). */
    private function hasTransparency(GdImage $image): bool
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $stepX = max(1, intdiv($w, 64));
        $stepY = max(1, intdiv($h, 64));

        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function fitsInMemory(int $bytesNeeded): bool
    {
        $limit = $this->memoryLimitBytes();

        if ($limit <= 0) {
            return true;
        }

        return memory_get_usage(true) + $bytesNeeded + 16 * 1024 * 1024 < $limit;
    }

    private function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return 0;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => (int) $raw,
        };
    }
}
