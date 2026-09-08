<?php

declare(strict_types=1);

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Support\HtmlString;

/**
 * Инлайновый SVG-QR без файлов и внешних запросов: код печатается на стикер
 * двери и открывает /track/{hash}. Рисуем сами по матрице энкодера —
 * готовый Svg-бэкенд тянет ext-xmlwriter, которого может не быть на хостинге.
 */
class QrCodeService
{
    public function svg(string $text, int $size = 220, int $quietZone = 2): HtmlString
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M())->getMatrix();
        $width = $matrix->getWidth();
        $total = $width + $quietZone * 2;

        $rects = '';

        for ($y = 0; $y < $width; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $rects .= sprintf(
                        '<rect x="%d" y="%d" width="1" height="1"/>',
                        $x + $quietZone,
                        $y + $quietZone,
                    );
                }
            }
        }

        return new HtmlString(sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" shape-rendering="crispEdges" role="img" aria-label="QR-код заказа">'
            .'<rect width="%1$d" height="%1$d" fill="#ffffff"/><g fill="#0f172a">%3$s</g></svg>',
            $total,
            $size,
            $rects,
        ));
    }
}
