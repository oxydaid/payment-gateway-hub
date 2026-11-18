<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Transaction;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Response;

class PaymentQrisController extends Controller
{
    /**
     * Dynamically render a QRIS QR code image (PNG or SVG) on-the-fly from the stored payment_code.
     */
    public function __invoke(string $referenceId, string $format = 'png'): Response
    {
        $transaction = Transaction::where('reference_id', $referenceId)->first();

        if (! $transaction || empty($transaction->payment_code) || ! str_starts_with($transaction->payment_code, '000201')) {
            abort(404, 'QRIS payment code not available for this transaction.');
        }

        $format = strtolower($format);
        $isSvg = $format === 'svg';

        $options = new QROptions([
            'outputInterface' => $isSvg ? QRMarkupSVG::class : QRGdImagePNG::class,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => 8,
            'quietzoneSize' => 4,
            'imageTransparent' => false,
            'bgColor' => [255, 255, 255],
            'svgAddXmlHeader' => true,
        ]);

        /** @var string $rendered */
        $rendered = (new QRCode($options))->render($transaction->payment_code);

        return response($rendered, 200, [
            'Content-Type' => $isSvg ? 'image/svg+xml' : 'image/png',
            'Cache-Control' => 'public, max-age=86400, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
