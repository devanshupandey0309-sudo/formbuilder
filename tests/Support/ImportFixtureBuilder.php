<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class ImportFixtureBuilder
{
    /**
     * @param  list<list<string>>  $rows
     */
    public static function createXlsx(array $rows, string $filename = 'import.xlsx'): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('import_', true).'-'.$filename;
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public static function createDocx(string $heading, array $tableRows, string $filename = 'import.docx'): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addTitle($heading, 1);

        $table = $section->addTable();

        foreach ($tableRows as $row) {
            $tableRow = $table->addRow();

            foreach ($row as $cellValue) {
                $tableRow->addCell(2000)->addText((string) $cellValue);
            }
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('import_', true).'-'.$filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }
}
