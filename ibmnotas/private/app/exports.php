<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function exportReport(array $offering, array $rows, string $format, bool $draft): void
{
    require_once __DIR__ . '/../vendor/autoload.php';
    $logo = __DIR__ . '/../public_html/assets/logo_ibm.png';
    $heading = 'PLANILLA DE NOTAS' . ($draft ? ' · BORRADOR' : '');
    $context = implode(' · ', [$offering['career'], $offering['subject'] . partLabel($offering), $offering['code'], $offering['module'],
        $offering['academic_year'], $offering['shift'], $offering['teacher']]);
    $columns = ['N.º', 'CI', 'Estudiante', 'Saber /40', 'Hacer /40', 'Ser /20', 'Final', 'Observación'];
    $filename = ($draft ? 'borrador-' : 'planilla-') . $offering['id'];
    header('Cache-Control: no-store');
    if ($format === 'pdf') {
        $html = '<style>body{font:10px DejaVu Sans} table{width:100%;border-collapse:collapse}'
            . 'td,th{border:1px solid #b5a8ad;padding:5px} th{background:#62243a;color:white}'
            . 'thead{display:table-header-group} tr{page-break-inside:avoid}.fail{color:#a12335}</style>'
            . '<img width="54" src="data:image/png;base64,' . base64_encode(file_get_contents($logo)) . '">'
            . '<h1>Instituto Técnico Británico Mercantil</h1><h2>' . e($heading) . '</h2><p>' . e($context) . '</p>'
            . '<table><thead><tr><th>' . implode('</th><th>', $columns) . '</th></tr></thead><tbody>';
        foreach ($rows as $index => $row) {
            $html .= '<tr><td>' . implode('</td><td>', array_map('e', [$index + 1, $row['ci'], $row['name'],
                $row['saber'], $row['hacer'], $row['ser'], $row['grade']]))
                . '</td><td class="' . ($row['grade'] !== null && $row['grade'] < 61 ? 'fail' : '') . '">'
                . e(observation($row['grade'])) . '</td></tr>';
        }
        $pdf = new Dompdf();
        $pdf->setPaper('letter');
        $pdf->loadHtml($html . '</tbody></table><p style="margin-top:40px">____________________ Docente &nbsp;&nbsp; ____________________ Dirección</p>', 'UTF-8');
        $pdf->render();
        header('Content-Type: application/pdf');
        header("Content-Disposition: attachment; filename=\"$filename.pdf\"");
        echo $pdf->output();
        return;
    }
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet()->setTitle('PLANILLA DE NOTAS');
    $setup = $sheet->getPageSetup();
    $setup->setPaperSize(PageSetup::PAPERSIZE_LETTER)->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
        ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 14)
        ->setHorizontalCentered(true);
    $sheet->freezePane('A15')->setShowGridLines(false);
    $image = new Drawing();
    $image->setPath($logo)->setCoordinates('B1')->setHeight(76)->setWorksheet($sheet);
    $write = static function (string $range, string $value) use ($sheet): void {
        $sheet->mergeCells($range);
        $sheet->setCellValueExplicit(explode(':', $range)[0], $value, DataType::TYPE_STRING);
    };
    $write('D3:J3', 'INSTITUTO TÉCNICO BRITÁNICO MERCANTIL');
    $write('H4:J4', 'IBM');
    $write('H5:J5', $offering['academic_year']);
    if ($draft) {
        $write('F6:J6', 'BORRADOR · PARA REVISIÓN');
    }
    $write('A7:J7', 'PLANILLA DE NOTAS');
    $write('D8:J8', 'ELABORADO EL ' . (new DateTimeImmutable('now', new DateTimeZone('America/La_Paz')))->format('d/m/Y'));
    $write('B9:C9', 'CARRERA:');
    $write('D9:J9', mb_strtoupper($offering['career']));
    $write('B10:C10', 'MATERIA:');
    $write('D10:J10', mb_strtoupper($offering['subject'] . partLabel($offering) . ($offering['code'] ? ' (' . $offering['code'] . ')' : '')));
    $write('B11:C11', 'CURSO:');
    $write('D11:E11', $offering['level'] . 'º AÑO');
    $write('F11:J11', mb_strtoupper(implode(' · ', [$offering['module'], $offering['academic_year'], $offering['shift']])));
    $write('B12:B14', 'N.º');
    $write('C12:E14', 'APELLIDOS Y NOMBRES');
    $write('F12:H12', 'DIMENSIONES');
    $write('I12:I14', $offering['series_id'] ? 'NOTA DE LA PARTE' : 'NOTA FINAL');
    $write('J12:J14', 'OBSERVACIONES');
    $sheet->fromArray(['SABER', 'HACER', 'SER'], null, 'F13')->fromArray(['40 PTS', '40 PTS', '20 PTS'], null, 'F14');
    $sheet->getStyle('A7:J7')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A7:J7')->getAlignment()->setHorizontal('center');
    $sheet->getStyle('B12:J14')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '62243A']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
    ]);
    foreach ([7=>24,9=>26,10=>26,12=>20,13=>24,14=>20] as $line => $height) {
        $sheet->getRowDimension($line)->setRowHeight($height);
    }
    $count = max(20, count($rows));
    $end = $count + 14;
    for ($index = 0; $index < $count; $index++) {
        $line = $index + 15;
        $row = $rows[$index] ?? null;
        $sheet->setCellValue("B$line", $index + 1)->mergeCells("C$line:E$line");
        if ($row) {
            $name = mb_strtoupper($row['name']) . ($row['is_retake'] ? "\nMATERIA PENDIENTE" : '');
            $sheet->setCellValueExplicit("C$line", $name, DataType::TYPE_STRING);
            $sheet->getRowDimension($line)->setRowHeight(max(17, 13 * (int)ceil(mb_strlen($row['name']) / 43) + ($row['is_retake'] ? 12 : 0)));
            $sheet->fromArray([$row['saber'], $row['hacer'], $row['ser']], null, "F$line");
            $legacy = $row['grade'] !== null && in_array(null, [$row['saber'], $row['hacer'], $row['ser']], true);
            $sheet->setCellValue("I$line", $legacy ? $row['grade'] : "=IF(COUNT(F$line:H$line)=3,ROUND(SUM(F$line:H$line),2),\"\")");
            $sheet->setCellValue("J$line", "=IF(ISNUMBER(I$line),IF(I$line>=61,\"APROBADO\",\"REPROBADO\"),\"PENDIENTE\")");
        }
        foreach (['F' => 40, 'G' => 40, 'H' => 20] as $column => $maximum) {
            $sheet->getCell($column . $line)->getDataValidation()->setType('decimal')->setOperator('between')
                ->setFormula1('0')->setFormula2((string)$maximum)->setAllowBlank(true)
                ->setShowErrorMessage(true)->setError('Valor fuera del rango permitido.');
        }
        if (($index + 1) % 20 === 0 && $index + 1 < $count) {
            $sheet->setBreak('A' . ($line + 1), PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_ROW);
        }
    }
    $sheet->getStyle('B12:J' . $end)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('A99CA1');
    $sheet->getStyle('C15:E' . $end)->getAlignment()->setWrapText(true);
    $sheet->getStyle('F15:I' . $end)->getNumberFormat()->setFormatCode('0.##');
    $conditional = new PhpOffice\PhpSpreadsheet\Style\Conditional();
    $conditional->setConditionType('expression')->addCondition('AND(ISNUMBER($I15),$I15<61)');
    $conditional->getStyle()->getFont()->getColor()->setRGB('A12335');
    $sheet->getStyle('B15:J' . $end)->setConditionalStyles([$conditional]);
    foreach (['A'=>2,'B'=>5,'C'=>12,'D'=>14,'E'=>14,'F'=>9,'G'=>9,'H'=>9,'I'=>9,'J'=>19] as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }
    $signature = $end + 5;
    $write("B$signature:E$signature", 'FIRMA DOCENTE · ' . mb_strtoupper($offering['teacher']));
    $write("G$signature:J$signature", 'FIRMA DIRECCIÓN ACADÉMICA');
    $setup->setPrintArea('A1:J' . $signature);
    $sheet->getHeaderFooter()->setOddFooter(($draft ? '&LBORRADOR' : '&LIBM') . '&RPÁGINA &P DE &N');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$filename.xlsx\"");
    (new Xlsx($book))->save('php://output');
}

function observation(mixed $grade): string
{
    return $grade === null ? 'PENDIENTE' : ($grade >= 61 ? 'APROBADO' : 'REPROBADO');
}
