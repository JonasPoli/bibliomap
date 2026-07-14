<?php

namespace App\Service\Qualis;

use Psr\Log\LoggerInterface;

class SucupiraScraperService
{
    private const BASE_URL = 'https://sucupira-legado.capes.gov.br/sucupira/public/consultas/coleta/veiculoPublicacaoQualis/listaConsultaGeralPeriodicos.jsf';

    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Fetches the Qualis classification of a journal by its ISSN from Plataforma Sucupira.
     *
     * @param string $issn The journal ISSN (formatted as XXXX-XXXX or XXXXXXXX)
     * @return string|null The Qualis classification (e.g. A1, A2, B1, etc.) or null if not found
     */
    public function fetchQualis(string $issn): ?string
    {
        $this->logger->info("Querying Sucupira for ISSN: {$issn}");

        // 1. GET request to initialize session cookies and retrieve ViewState
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::BASE_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, true); // Retain headers to parse cookies
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->logger->warning("Failed to perform initial GET request to Sucupira. HTTP Code: {$httpCode}");
            return null;
        }

        // Parse Cookies correctly without parse_str to prevent '+' sign conversions
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);
        $cookies = [];
        foreach ($matches[1] as $item) {
            $cookieParts = explode('=', $item, 2);
            if (count($cookieParts) === 2) {
                $name = trim($cookieParts[0]);
                $value = trim($cookieParts[1]);
                $cookies[$name] = $value;
            }
        }

        $cookieStr = '';
        foreach ($cookies as $name => $value) {
            $cookieStr .= "$name=$value; ";
        }

        // Parse javax.faces.ViewState
        if (!preg_match('/name="javax.faces.ViewState"[^>]*value="([^"]+)"/', $response, $matchesViewState)) {
            if (!preg_match('/value="([^"]+)"[^>]*name="javax.faces.ViewState"/', $response, $matchesViewState)) {
                $this->logger->warning("Could not find javax.faces.ViewState in Sucupira HTML response.");
                return null;
            }
        }
        $viewState = $matchesViewState[1];

        // 2. Query event 237 (2021-2024 quadrennium) first, fall back to event 236 (2017-2020)
        $qualis = $this->queryEvent($issn, '237', $cookieStr, $viewState);
        if (!$qualis) {
            $this->logger->info("No Qualis found for ISSN {$issn} in quadrennium 2021-2024. Falling back to 2017-2020.");
            $qualis = $this->queryEvent($issn, '236', $cookieStr, $viewState);
        }

        return $qualis;
    }

    /**
     * Executes the POST query for a specific event/quadrennium.
     */
    private function queryEvent(string $issn, string $eventId, string $cookieStr, string $viewState): ?string
    {
        $payload = http_build_query([
            'form' => 'form',
            'form:evento' => $eventId,
            'form:checkIssn' => 'on',
            'form:issn:issn' => $issn,
            'form:consultar' => 'Consultar',
            'javax.faces.ViewState' => $viewState
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::BASE_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://sucupira-legado.capes.gov.br',
            'Referer: ' . self::BASE_URL
        ];
        if ($cookieStr) {
            $headers[] = 'Cookie: ' . rtrim($cookieStr, '; ');
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->logger->warning("POST query failed for ISSN {$issn} on Event {$eventId}. HTTP Code: {$httpCode}");
            return null;
        }

        // Parse HTML results using DOMDocument and DOMXPath
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $response);
        $xpath = new \DOMXPath($dom);

        // Find table rows
        $rows = $xpath->query('//table//tr');
        foreach ($rows as $row) {
            $cols = $xpath->query('td', $row);
            if ($cols->length >= 4) {
                $qualisVal = strtoupper(trim($cols->item(3)->textContent));
                // Validate if it is a valid Qualis rank (A1-A4, B1-B5, C)
                if (preg_match('/^[A-C][1-5]?$/', $qualisVal)) {
                    $this->logger->info("Successfully resolved Qualis '{$qualisVal}' for ISSN {$issn} from Sucupira.");
                    return $qualisVal;
                }
            }
        }

        return null;
    }

    /**
     * Fetches detailed publication rows from Sucupira for a given ISSN.
     *
     * @param string $issn The ISSN
     * @return array Array of associative arrays containing: issn, title, area, qualis, mother_area
     */
    public function fetchDetailedRows(string $issn): array
    {
        $this->logger->info("Fetching detailed Sucupira rows for ISSN: {$issn}");

        // 1. GET request to initialize session cookies and retrieve ViewState
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::BASE_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, yGecko) Chrome/120.0.0.0 Safari/537.36');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return [];
        }

        // Parse Cookies
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);
        $cookies = [];
        foreach ($matches[1] as $item) {
            $cookieParts = explode('=', $item, 2);
            if (count($cookieParts) === 2) {
                $name = trim($cookieParts[0]);
                $value = trim($cookieParts[1]);
                $cookies[$name] = $value;
            }
        }

        $cookieStr = '';
        foreach ($cookies as $name => $value) {
            $cookieStr .= "$name=$value; ";
        }

        // Parse javax.faces.ViewState
        if (!preg_match('/name="javax.faces.ViewState"[^>]*value="([^"]+)"/', $response, $matchesViewState)) {
            if (!preg_match('/value="([^"]+)"[^>]*name="javax.faces.ViewState"/', $response, $matchesViewState)) {
                return [];
            }
        }
        $viewState = $matchesViewState[1];

        // 2. Query event 237 (2021-2024 quadrennium) first, fall back to event 236 (2017-2020)
        $rows = $this->queryEventRows($issn, '237', $cookieStr, $viewState);
        if (empty($rows)) {
            $this->logger->info("No rows found for ISSN {$issn} in quadrennium 2021-2024. Falling back to 2017-2020.");
            $rows = $this->queryEventRows($issn, '236', $cookieStr, $viewState);
        }

        return $rows;
    }

    private function queryEventRows(string $issn, string $eventId, string $cookieStr, string $viewState): array
    {
        $payload = http_build_query([
            'form' => 'form',
            'form:evento' => $eventId,
            'form:checkIssn' => 'on',
            'form:issn:issn' => $issn,
            'form:consultar' => 'Consultar',
            'javax.faces.ViewState' => $viewState
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::BASE_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://sucupira-legado.capes.gov.br',
            'Referer: ' . self::BASE_URL
        ];
        if ($cookieStr) {
            $headers[] = 'Cookie: ' . rtrim($cookieStr, '; ');
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return [];
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $response);
        $xpath = new \DOMXPath($dom);

        $rows = $xpath->query('//table//tr');
        $result = [];
        foreach ($rows as $row) {
            $cols = $xpath->query('td', $row);
            if ($cols->length >= 4) {
                $qualisVal = strtoupper(trim($cols->item(3)->textContent));
                if (preg_match('/^[A-C][1-5]?$/', $qualisVal)) {
                    $result[] = [
                        'issn' => trim($cols->item(0)->textContent),
                        'title' => trim($cols->item(1)->textContent),
                        'area' => trim($cols->item(2)->textContent),
                        'qualis' => $qualisVal,
                        'mother_area' => $cols->length >= 5 ? trim($cols->item(4)->textContent) : ''
                    ];
                }
            }
        }

        return $result;
    }
}
