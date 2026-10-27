<?php

namespace App\Services\Content\Search;

use Bitrix\Main\Context;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Class ExportSpreadsheetService
 * Компонент генерации документов Excel на основе коллекции поисковых DTO.
 */
class ExportSpreadsheetService
{
    /** @var SearchResultItemDto */
    protected array $collection;

    /**
     * @param SearchResultItemDto $collection Массив объектов результатов поиска
     */
    public function __construct(array $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Создает файл Excel и сохраняет его в директорию на сервере.
     *
     * @param string $outputFilename Название генерируемого файла
     * @return string Относительный путь к созданному файлу от корня сайта
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function generateReport(string $outputFilename = 'searchContent.xlsx'): string
    {
        $documentRoot = Context::getCurrent()->getServer()->getDocumentRoot();

        // Формируем структуру заголовков
        $sheetPayload = [
            [
                'ID',
                'Название элемента',
                'Ссылка на элемент',
                'Инфоблок',
            ],
        ];

        // Наполняем данными из DTO-структур
        foreach ($this->collection as $item) {
            if (!$item instanceof SearchResultItemDto) {
                continue;
            }

            $sheetPayload[] = [
                $item->id,
                $item->name,
                $item->adminEditLink,
                $item->iblockName,
            ];
        }

        $spreadsheet = new Spreadsheet();
        $activeSheet = $spreadsheet->getActiveSheet();
        $activeSheet->setTitle('Результаты');

        // Пакетная выгрузка массива в таблицу
        $activeSheet->fromArray($sheetPayload);

        // Статическая конфигурация ширины колонок
        $columnWidthMap = [
            'A' => 12,
            'B' => 35,
            'C' => 60,
            'D' => 25,
        ];

        $highestColumn = $activeSheet->getHighestColumn();
        foreach (range('A', $highestColumn) as $columnKey) {
            $width = $columnWidthMap[$columnKey] ?? 20;
            $activeSheet->getColumnDimension($columnKey)->setWidth($width);
        }

        $relativeUploadPath = '/upload/exports/';
        $absoluteTargetDir = $documentRoot . $relativeUploadPath;

        if (!is_dir($absoluteTargetDir)) {
            mkdir($absoluteTargetDir, 0775, true);
        }

        $writer = IOFactory::createWriter($spreadsheet, "Xlsx");
        $writer->save($absoluteTargetDir . $outputFilename);

        return $relativeUploadPath . $outputFilename;
    }
}
