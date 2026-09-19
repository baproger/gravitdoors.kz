<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Uploads\UploadCompressor;
use Tests\TestCase;

/**
 * Сжатие загрузок: большое фото уменьшается и худеет, прозрачность не теряется,
 * то, что сжимать нечем или незачем, остаётся как есть.
 */
class UploadCompressorTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_large_photo_is_resized_to_the_long_side_and_becomes_jpeg(): void
    {
        $source = $this->image(3200, 2400, 'png');
        $before = filesize($source);

        $result = (new UploadCompressor)->compress($source, 'image/png');

        $this->assertNotNull($result);
        $this->temp[] = $result['path'];
        $this->assertSame('jpg', $result['extension']);

        [$w, $h] = getimagesize($result['path']);
        $this->assertSame(1600, $w);
        $this->assertSame(1200, $h);
        $this->assertLessThan($before, filesize($result['path']));
    }

    public function test_portrait_photo_keeps_orientation_and_ratio(): void
    {
        $source = $this->image(1500, 3000, 'jpeg');

        $result = (new UploadCompressor)->compress($source, 'image/jpeg');

        $this->assertNotNull($result);
        $this->temp[] = $result['path'];
        [$w, $h] = getimagesize($result['path']);
        $this->assertSame(800, $w);
        $this->assertSame(1600, $h);
    }

    public function test_transparent_png_becomes_webp_and_keeps_alpha(): void
    {
        $source = $this->image(2000, 2000, 'png', transparent: true);

        $result = (new UploadCompressor)->compress($source, 'image/png');

        $this->assertNotNull($result);
        $this->temp[] = $result['path'];
        $this->assertSame('webp', $result['extension']);

        $image = imagecreatefromwebp($result['path']);
        $alpha = (imagecolorat($image, 5, 5) >> 24) & 0x7F;
        $this->assertGreaterThan(0, $alpha, 'угол должен остаться прозрачным');
    }

    public function test_small_image_that_would_not_shrink_is_left_alone(): void
    {
        // Крошечный JPEG уже меньше, чем получится после пережатия.
        $source = $this->image(200, 150, 'jpeg', quality: 40);

        $this->assertNull((new UploadCompressor)->compress($source, 'image/jpeg'));
    }

    public function test_word_documents_and_unknown_files_are_not_touched(): void
    {
        $doc = tempnam(sys_get_temp_dir(), 'gravit-doc-');
        file_put_contents($doc, str_repeat('PK docx-like bytes ', 1000));
        $this->temp[] = $doc;

        $this->assertNull((new UploadCompressor)->compress($doc, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertNull((new UploadCompressor)->compress($doc, 'text/plain'));
    }

    public function test_pdf_is_left_alone_when_ghostscript_is_disabled(): void
    {
        config()->set('gravit.uploads.pdf', false);
        $pdf = tempnam(sys_get_temp_dir(), 'gravit-pdf-');
        file_put_contents($pdf, "%PDF-1.4\n".str_repeat('stream bytes ', 500));
        $this->temp[] = $pdf;

        $this->assertNull((new UploadCompressor)->compress($pdf, 'application/pdf'));
    }

    public function test_max_side_and_quality_come_from_config(): void
    {
        config()->set('gravit.uploads.max_side', 800);
        $source = $this->image(2400, 1200, 'jpeg');

        $result = (new UploadCompressor)->compress($source, 'image/jpeg');

        $this->assertNotNull($result);
        $this->temp[] = $result['path'];
        [$w, $h] = getimagesize($result['path']);
        $this->assertSame(800, $w);
        $this->assertSame(400, $h);
    }

    private function image(int $w, int $h, string $format, bool $transparent = false, int $quality = 95): string
    {
        $image = imagecreatetruecolor($w, $h);

        if ($transparent) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        } else {
            imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 240));
        }

        // Шум, чтобы файл не был вырожденно маленьким: у реального чека есть детали.
        for ($i = 0; $i < 4000; $i++) {
            $x = random_int(intdiv($w, 4), $w - 1);
            $y = random_int(intdiv($h, 4), $h - 1);
            imagesetpixel($image, $x, $y, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }

        $path = tempnam(sys_get_temp_dir(), 'gravit-src-');
        $this->temp[] = $path;

        $format === 'png' ? imagepng($image, $path) : imagejpeg($image, $path, $quality);
        imagedestroy($image);

        return $path;
    }
}
