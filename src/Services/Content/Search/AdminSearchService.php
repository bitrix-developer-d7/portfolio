<?php

namespace App\Services\Content\Search;

use Bitrix\Main\Context;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * Иммутабельный Data Transfer Object для нормализации результатов поиска.
 */
final class SearchResultItemDto
{
    public function __construct(
        public int $id,
        public string $name,
        public int $iblockId,
        public string $iblockTypeId,
        public string $iblockName,
        public int $sectionId,
        public string $adminEditLink
    ) {}
}

/**
 * Репозиторий для высокопроизводительной выборки данных из ядра 1С-Битрикс (D7 ORM).
 */
class ElementRepository
{
    protected int $totalRecordsCount = 0;

    /**
     * Унифицированный метод сквозного поиска по контенту и свойствам.
     * Избавляет от дублирования логики веб-выдачи и выгрузки файлов (Принцип DRY).
     */
    public function findByText(string $searchPhrase, ?PageNavigation $nav = null): array
    {
        $searchPhrase = trim($searchPhrase);
        if (str_contains($searchPhrase, '%')) {
            $searchPhrase = str_replace('%', '\%', $searchPhrase);
        }

        // Заменено кастомное решение ElementTable на стандартное ядро Битрикс инфоблоков
        $query = \Bitrix\Iblock\ElementTable::query()
            ->registerRuntimeField(new Reference(
                                       'PROPERTY_REF',
                                       ElementPropertyTable::class,
                                       Join::on('this.ID', 'ref.IBLOCK_ELEMENT_ID')
                                   ))
            ->registerRuntimeField(new ExpressionField(
                                       'CONCATENATED_VALUES',
                                       'GROUP_CONCAT(%s)',
                                       ['PROPERTY_REF.VALUE']
                                   ))
            ->select(['ID', 'IBLOCK_ID', 'NAME', 'IBLOCK_SECTION_ID', 'CONCATENATED_VALUES'])
            ->where('ACTIVE', 'Y')
            ->whereNull('WF_PARENT_ELEMENT_ID');

        if ($searchPhrase !== '') {
            $query->where(
                Query::filter()
                    ->logic('or')
                    ->whereLike('NAME', '%' . $searchPhrase . '%')
                    ->whereLike('SEARCHABLE_CONTENT', '%' . $searchPhrase . '%')
                    ->whereLike('PROPERTY_REF.VALUE', '%' . $searchPhrase . '%')
            );
        }

        $query->setCacheTtl(1800 + random_int(15, 45));

        if ($nav) {
            $query->setLimit($nav->getLimit());
            $query->setOffset($nav->getOffset());
            $query->countTotal(true);

            $dbResult = $query->exec();
            $this->totalRecordsCount = $dbResult->getCount();
            return $dbResult->fetchAll();
        }

        return $query->exec()->fetchAll();
    }

    public function getLatestTotalCount(): int
    {
        return $this->totalRecordsCount;
    }

    /**
     * Оптимизация: Пакетный сбор метаданных инфоблоков.
     * Решает проблему N+1 запросов к БД в цикле (Eager Loading).
     */
    public function preloadIblocksMeta(array $iblockIds): array
    {
        if (empty($iblockIds)) {
            return [];
        }

        $metaMap = [];
        $dbRes = IblockTable::query()
            ->select(['ID', 'IBLOCK_TYPE_ID', 'NAME'])
            ->whereIn('ID', $iblockIds)
            ->setCacheTtl(86400)
            ->exec();

        while ($iblock = $dbRes->fetch()) {
            $metaMap[(int)$iblock['ID']] = [
                'TYPE_ID' => $iblock['IBLOCK_TYPE_ID'],
                'NAME'    => $iblock['NAME'],
            ];
        }

        return $metaMap;
    }
}

/**
 * Слой бизнес-логики (Сервис-Фасад) для координации поисковых процессов в админ-панели.
 */
class AdminSearchService
{
    protected ElementRepository $repository;

    public function __construct(?ElementRepository $repository = null)
    {
        $this->repository = $repository ?: new ElementRepository();
    }

    /**
     * Выполнение поиска и сборка коллекции DTO.
     *
     * @return SearchResultItemDto[]
     */
    public function getSearchPayload(?PageNavigation $nav = null): array
    {
        $request = Context::getCurrent()->getRequest();
        $searchPhrase = (string)$request->get('search');

        $rawElements = $this->repository->findByText($searchPhrase, $nav);
        if (empty($rawElements)) {
            return [];
        }

        // Оптимизация: собираем инфоблоки за один единственный запрос
        $iblockIds = array_unique(array_column($rawElements, 'IBLOCK_ID'));
        $iblocksMeta = $this->repository->preloadIblocksMeta($iblockIds);

        $dtoCollection = [];
        foreach ($rawElements as $row) {
            $id = (int)$row['ID'];
            $iblockId = (int)$row['IBLOCK_ID'];
            $sectionId = (int)($row['IBLOCK_SECTION_ID'] ?: 0);

            $meta = $iblocksMeta[$iblockId] ?? ['TYPE_ID' => 'content', 'NAME' => 'Инфоблок'];

            $dtoCollection[] = new SearchResultItemDto(
                id: $id,
                name: $row['NAME'],
                iblockId: $iblockId,
                iblockTypeId: $meta['TYPE_ID'],
                iblockName: $meta['NAME'],
                sectionId: $sectionId,
                adminEditLink: $this->buildAdminLink($id, $iblockId, $sectionId, $meta['TYPE_ID'])
            );
        }

        return $dtoCollection;
    }

    public function getTotalCount(): int
    {
        return $this->repository->getLatestTotalCount();
    }

    protected function buildAdminLink(int $id, int $iblockId, int $sectionId, string $typeId): string
    {
        return sprintf(
            '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=%d&type=%s&lang=ru&ID=%d&find_section_section=%d&WF=Y',
            $iblockId,
            $typeId,
            $id,
            $sectionId
        );
    }
}
