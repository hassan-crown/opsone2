<?php

namespace App\Agreements\Signing;

use Mpdf\Mpdf;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Turns the PDF Blade views into bytes with mPDF (PHP-native, ADR-0029): Arabic shaping and bidi through its OpenType
 * layout engine with the self-hosted IBM Plex Sans Arabic TTF files (Latin glyphs included), so nothing outside the
 * PHP image is needed. Images travel as in-memory variables (`var:name`), never as URLs: mPDF fetches no remote file.
 */
final class PdfRenderer
{
    /**
     * @param  array<string, string>  $images  name => bytes
     */
    public function render(string $html, array $images, string $title): string
    {
        $pdf = $this->engine($images, $title);
        $pdf->WriteHTML($html);

        return $pdf->Output('', 'S');
    }

    /**
     * The original PDF's pages unchanged, followed by a certificate page.
     *
     * @param  array<string, string>  $images
     */
    public function appendPage(string $original, string $html, array $images, string $title): string
    {
        $pdf = $this->engine($images, $title);
        $pages = $pdf->setSourceFile(StreamReader::createByString($original));
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->useTemplate($pdf->importPage($i));
        }
        $pdf->AddPage();
        $pdf->WriteHTML($html);

        return $pdf->Output('', 'S');
    }

    /**
     * @param  array<string, string>  $images
     */
    private function engine(array $images, string $title): Mpdf
    {
        $tmp = storage_path('framework/cache/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 18,
            'margin_footer' => 8,
            'tempDir' => $tmp,
            'fontDir' => [resource_path('fonts/pdf')],
            'fontdata' => [
                'plex' => [
                    'R' => 'IBMPlexSansArabic-Regular.ttf',
                    'B' => 'IBMPlexSansArabic-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font' => 'plex',
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            'showImageErrors' => false,
        ]);
        $pdf->SetTitle($title);
        $pdf->SetCreator('OpsOne');
        foreach ($images as $name => $bytes) {
            $pdf->imageVars[$name] = $bytes;
        }

        return $pdf;
    }
}
