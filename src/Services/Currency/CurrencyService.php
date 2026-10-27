<?php

namespace App\Services\Market;

use Bitrix\Main\Loader;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\SystemException;
use Bitrix\Highloadblock\HighloadBlockTable;

/**
 * Interface CurrencyProviderInterface
 * Абстракция для получения курсов из различных внешних источников.
 */
interface CurrencyProviderInterface
{
    public function fetchRates(string $date): array;
}

/**
 * Class CentralBankProvider
 * Реализация провайдера данных для ЦБ РФ.
 */
class CentralBankProvider implements CurrencyProviderInterface
{
    private const API_ENDPOINT = 'https://cbr.ru';
    private const TIMEOUT = 10;

    public function fetchRates(string $date): array
    {
        $client = new HttpClient([
                                     'socketTimeout' => self::TIMEOUT,
                                     'streamTimeout' => self::TIMEOUT,
                                 ]);

        $response = $client->get(self::API_ENDPOINT . $date);
        if (!$response) {
            return [];
        }

        $xml = simplexml_load_string($response);
        if (!$xml) {
            return [];
        }

        $encoded = json_encode($xml);
        $decoded = json_decode($encoded, true);

        return $decoded['Valute'] ?? [];
    }
}

/**
 * Class ExchangeRateService
 * Сервис управления коммерческими курсами валют с поддержкой кэширования в HL-блоки.
 */
class ExchangeRateService
{
    private const DEFAULT_ENTITY_NAME = 'ExchangeRates';
    private const BASE_MARGIN = 0.06; // 6% маржинальность операции

    protected object $dataManager;
    protected CurrencyProviderInterface $provider;

    protected string $currentDate;
    protected string $previousDate;

    protected array $allowedCodes;
    protected array $repository = [];

    public function __construct(
        array $allowedCodes = [],
        ?CurrencyProviderInterface $provider = null,
        ?string $targetDate = null,
        string $entityName = self::DEFAULT_ENTITY_NAME
    ) {
        $this->provider = $provider ?: new CentralBankProvider();
        $this->dataManager = $this->resolveBitrixEntity($entityName);
        $this->allowedCodes = $allowedCodes;

        $this->initTimeline($targetDate);
        $this->synchronize();
    }

    protected function initTimeline(?string $targetDate): void
    {
        if ($targetDate) {
            $current = date_create($targetDate);
            $this->currentDate = date_format($current, 'd/m/Y');

            $previous = date_create($targetDate);
            date_modify($previous, '-1 day');
            $this->previousDate = date_format($previous, 'd/m/Y');
        } else {
            $this->currentDate = date('d/m/Y');
            $this->previousDate = date('d/m/Y', strtotime('-1 day'));
        }
    }

    protected function synchronize(): void
    {
        $this->repository = $this->loadFromStorage();

        if (empty($this->repository)) {
            $this->repository = $this->processExternalData();
        }
    }

    protected function loadFromStorage(): array
    {
        $storageData = [];
        try {
            $query = $this->dataManager::getList([
                                                     'select' => ['*'],
                                                     'filter' => ['=UF_DATE' => $this->currentDate],
                                                 ]);

            while ($row = $query->fetch()) {
                $storageData[] = [
                    'ID'       => $row['ID'],
                    'NAME'     => $row['UF_NAME'],
                    'CODE'     => $row['UF_CODE'],
                    'BUY'      => round((float)$row['UF_BUY'], 4),
                    'SELL'     => round((float)$row['UF_SELL'], 4),
                    'MARGIN'   => round((float)$row['UF_MARGIN_DIFF'], 4),
                    'DATETIME' => $this->currentDate,
                ];
            }
        } catch (\Exception $e) {
            return [];
        }

        return $storageData;
    }

    protected function processExternalData(): array
    {
        $todayRaw = $this->provider->fetchRates($this->currentDate);
        $yesterdayRaw = $this->provider->fetchRates($this->previousDate);

        $historyIndex = [];
        foreach ($yesterdayRaw as $rate) {
            if (isset($rate['CharCode'])) {
                $historyIndex[$rate['CharCode']] = $rate;
            }
        }

        $processedCollection = [];
        foreach ($todayRaw as $index => $rate) {
            if (!isset($rate['Value'], $rate['CharCode'])) {
                continue;
            }

            $code = $rate['CharCode'];
            $value = (float)str_replace(',', '.', $rate['Value']);
            $nominal = (float)($rate['Nominal'] ?? 1);

            $oldValue = 0.0;
            if (isset($historyIndex[$code]['Value'])) {
                $oldValue = (float)str_replace(',', '.', $historyIndex[$code]['Value']);
            }
            $delta = $value - $oldValue;

            $unitPrice = $value / $nominal;
            $purchasePrice = round($unitPrice * (1 - self::BASE_MARGIN), 4);
            $sellingPrice  = round($unitPrice * (1 + self::BASE_MARGIN), 4);

            $payload = [
                'UF_NAME'        => $rate['Name'] ?? '',
                'UF_CODE'        => $code,
                'UF_NOMINAL'     => $nominal,
                'UF_BUY'         => $purchasePrice,
                'UF_SELL'        => $sellingPrice,
                'UF_MARGIN_DIFF' => round($delta, 4),
                'UF_DATE'        => $this->currentDate,
                'UF_SORT'        => (int)$index * 100,
            ];

            $this->dataManager::add($payload);
            $processedCollection[] = $payload;
        }

        return $processedCollection;
    }

    public function getFormattedPayload(): array
    {
        $output = [];
        foreach ($this->repository as $record) {
            $currencyCode = $record['CODE'] ?? $record['UF_CODE'] ?? '';

            if (!empty($this->allowedCodes) && !in_array($currencyCode, $this->allowedCodes)) {
                continue;
            }

            $diff = $record['MARGIN'] ?? $record['UF_MARGIN_DIFF'] ?? 0.0;
            $isPositive = $diff > 0;

            $output[] = [
                'currency' => $currencyCode,
                'label'    => $record['NAME'] ?? $record['UF_NAME'] ?? '',
                'purchase' => [
                    'amount'   => $record['BUY'] ?? $record['UF_BUY'] ?? 0.0,
                    'is_up'    => $isPositive,
                    'offset'   => $diff,
                ],
                'selling'  => [
                    'amount'   => $record['SELL'] ?? $record['UF_SELL'] ?? 0.0,
                    'is_up'    => $isPositive,
                    'offset'   => $diff,
                ],
            ];
        }

        return $output;
    }

    protected function resolveBitrixEntity(string $entityName)
    {
        if (!Loader::includeModule('highloadblock')) {
            throw new SystemException('Required module "highloadblock" is missing.');
        }

        $hlBlock = HighloadBlockTable::query()
            ->addSelect('*')
            ->addFilter('NAME', $entityName)
            ->exec()
            ->fetch();

        if (!$hlBlock) {
            throw new SystemException(sprintf('Entity "%s" could not be resolved.', $entityName));
        }

        return HighloadBlockTable::compileEntity($hlBlock)->getDataClass();
    }
}
