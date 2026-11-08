<?php

namespace App\Help\Pdf;

use App\Agreements\Signing\PdfRenderer;
use Illuminate\Support\Carbon;
use Mpdf\HTMLParserMode;

/**
 * Prints a RoleBook to PDF with mPDF (ADR-0049: the engine ADR-0029 already ships; Arabic shaping and the platform's
 * IBM Plex Sans Arabic TTF).
 *
 * The HTML is fed to mPDF in pieces split at `<pagebreak />` and `<!--anchor:id-->` markers. mPDF cannot register
 * `<a name>` destinations once the document holds Arabic text, so each anchor's page and position are recorded here and
 * the `href="#id"` links are resolved by hand. The same positions give the printed page numbers: the book is printed
 * twice, the first pass learns where each section and article starts, the second fills the contents in (the number
 * column has a fixed width, so the layout does not move).
 */
final class RoleBookPdf
{
    public function __construct(private readonly RoleBookHtml $html = new RoleBookHtml) {}

    public function render(RoleBook $book, string $root, ?Carbon $issued = null): string
    {
        $first = $this->print($book, $root, $issued, []);

        return $this->print($book, $root, $issued, $first['pages'])['pdf'];
    }

    /**
     * @param  array<string, int>  $pages
     * @return array{pdf: string, pages: array<string, int>}
     */
    private function print(RoleBook $book, string $root, ?Carbon $issued, array $pages): array
    {
        ['html' => $html, 'images' => $images] = $this->html->render($book, $root, $issued, $pages);

        $pdf = (new PdfRenderer)->engine($images, 'دليل '.$book->title.' — OpsOne', [
            'margin_top' => 22,
            'margin_bottom' => 20,
            'margin_header' => 8,
            'margin_footer' => 8,
        ]);
        $pdf->SetDirectionality('rtl');

        $title = e($book->title);
        $row = fn (string $edge, string $left, string $right) => '<table width="100%" style="font-family: plex; font-size: 8.5pt; color: #6b7280; border-collapse: collapse;"><tr>'
            .'<td width="50%" style="border: 0; '.$edge.': 0.5pt solid #c9d0d6; text-align: right;">'.$left.'</td>'
            .'<td width="50%" style="border: 0; '.$edge.': 0.5pt solid #c9d0d6; text-align: left;">'.$right.'</td></tr></table>';
        $pdf->SetHTMLHeader($row('border-bottom', '<b>OpsOne</b>', 'دليل '.$title));
        $pdf->SetHTMLFooter($row('border-top', 'OpsOne — '.$title, 'صفحة {PAGENO}'));

        preg_match('#<style>(.*?)</style>#s', $html, $css);
        preg_match('#<body>(.*)</body>#s', $html, $body);
        $pdf->WriteHTML($css[1] ?? '', HTMLParserMode::HEADER_CSS);

        // Page 1 is the cover (no header or footer); page numbering restarts at 1 on the page after it.
        $pdf->AddPage('', '', '', '', 'on');
        $anchors = [];
        $started = false;
        foreach (preg_split('#(<pagebreak />|<!--anchor:[^>]+-->)#', $body[1] ?? '', -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
            if ($piece === '<pagebreak />') {
                $started ? $pdf->AddPage() : $pdf->AddPage('', '', 1, '1', 'off');
                $started = true;
            } elseif (str_starts_with($piece, '<!--anchor:')) {
                $anchors[substr($piece, 11, -3)] = [(int) $pdf->page, (float) $pdf->y];
            } elseif (trim($piece) !== '') {
                $pdf->WriteHTML($piece, HTMLParserMode::HTML_BODY);
            }
        }

        // mPDF numbers every reference to the same target ("#id", "##id", …).
        $found = [];
        foreach ($anchors as $id => [$page, $y]) {
            $found[$id] = max(1, $page - 1);
            for ($key = '#'.$id; isset($pdf->internallink[$key]); $key = '#'.$key) {
                $pdf->SetLink($pdf->internallink[$key], $y, $page);
            }
        }

        return ['pdf' => $pdf->Output('', 'S'), 'pages' => $found];
    }
}
