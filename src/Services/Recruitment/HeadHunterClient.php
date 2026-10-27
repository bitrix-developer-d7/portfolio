<?php

namespace App\Services\Recruitment;

use GuzzleHttp\Client;
use GuzzleHttp\Utils;
use GuzzleHttp\Exception\GuzzleException;

interface VacancyProviderInterface
{
    public function fetchVacancies(int $page, int $limit): array;
    public function fetchDetail(int $id): array;
}

/**
 * Class HeadHunterClient
 * Абстрагированный сервис интеграции с рекрутинговой платформой hh.ru API.
 */
class HeadHunterClient implements VacancyProviderInterface
{
    private const FALLBACK_COMPANY_ID = 098765;
    private const API_GATEWAY         = 'https://api.hh.ru/';
    private const CONNECTION_TIMEOUT  = 15.0;

    protected Client $httpClient;
    protected int $companyId;

    /**
     * Конструктор принимает кастомный ID или подтягивает его из безопасного окружения.
     */
    public function __construct(?int $companyId = null, array $clientConfig = [])
    {
        $this->companyId = $companyId ?: (int)getenv('RECRUITMENT_PROVIDER_ID') ?: self::FALLBACK_COMPANY_ID;

        $defaultConfig = [
            'base_uri' => self::API_GATEWAY,
            'timeout'  => self::CONNECTION_TIMEOUT,
            'headers'  => [
                'User-Agent' => 'App-Recruitment-Agent/1.0 (contacts@example.com)', // Важное требование hh.ru API
            ],
        ];

        $this->httpClient = new Client(array_merge($defaultConfig, $clientConfig));
    }

    /**
     * Получение постраничного списка активных вакансий компании.
     *
     * @param int $page Текущая страница
     * @param int $limit Количество элементов на странице
     * @return array Массив вакансий
     * @throws GuzzleException
     */
    public function fetchVacancies(int $page = 0, int $limit = 20): array
    {
        $payload = [
            'employer_id' => $this->companyId,
            'per_page'    => $limit,
            'page'        => $page,
        ];

        try {
            $response = $this->httpClient->request('GET', 'vacancies', [
                'query' => $payload,
            ]);

            $body = $response->getBody()->getContents();
            $data = Utils::jsonDecode($body, true);

            return $data['items'] ?? [];
        } catch (\Exception $e) {
            // В продакшене здесь должен быть проброс своего кастомного Exception или логирование
            return [];
        }
    }

    /**
     * Получение подробной информации о конкретной вакансии.
     *
     * @param int $id Идентификатор вакансии
     * @return array Данные вакансии
     * @throws GuzzleException
     */
    public function fetchDetail(int $id): array
    {
        try {
            $response = $this->httpClient->request('GET', "vacancies/{$id}");
            $body = $response->getBody()->getContents();

            return Utils::jsonDecode($body, true) ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
