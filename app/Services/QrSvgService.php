<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrSvgService
{
    public function render(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 4), new SvgImageBackEnd));

        return $writer->writeString($url, 'UTF-8', ErrorCorrectionLevel::M());
    }
}
