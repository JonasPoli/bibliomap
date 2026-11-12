<?php

namespace App\Service\Qualis;

use Psr\Log\LoggerInterface;

class SucupiraScraperService
{
    private const BASE_URL = 'https://sucupira-legado.capes.gov.br/sucupira/public/consultas/coleta/veiculoPublicacaoQualis/listaConsultaGeralPeriodicos.jsf';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

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
        return $this->lookupQualis($issn)['qualis'];
    }

    /**
     * Consulta o estrato e diz se a Sucupira respondeu. "reached" false é falha de rede/HTTP
     * (vale consultar de novo depois); "reached" true com qualis null é revista sem estrato.
     *
     * @return array{qualis: ?string, reached: bool}
     */
    public function lookupQualis(string $issn): array
    {
        $this->logger->info("Querying Sucupira for ISSN: {$issn}");

        $session = $this->openSession();
        if ($session === null) {
            return ['qualis' => null, 'reached' => false];
        }

        // Quadriênio 2021-2024 (evento 237) primeiro; sem resultado, 2017-2020 (evento 236)
        $reached = true;
        foreach (['237', '236'] as $eventId) {
            $rows = $this->queryEventRows($issn, $eventId, $session['cookieStr'], $session['viewState']);
            if ($rows === null) {
                $reached = false;
                continue;
            }
            if ($rows !== []) {
                $this->logger->info("Successfully resolved Qualis '{$rows[0]['qualis']}' for ISSN {$issn} from Sucupira.");
                return ['qualis' => $rows[0]['qualis'], 'reached' => true];
            }
            $this->logger->info("No Qualis found for ISSN {$issn} on event {$eventId}.");
        }

        return ['qualis' => null, 'reached' => $reached];
    }

    /**
     * Fetches detailed publication rows from Sucupira for a given ISSN.
     *
     * @param string $issn The ISSN
     * @return array Vazio em caso de falha; senão rows (issn, title, area, qualis, mother_area), jsessionid, viewstate e eventid
     */
    public function fetchDetailedRows(string $issn): array
    {
        $this->logger->info("Fetching detailed Sucupira rows for ISSN: {$issn}");

        $session = $this->openSession();
        if ($session === null) {
            return [];
        }

        $eventId = '237';
        $rows = $this->queryEventRows($issn, $eventId, $session['cookieStr'], $session['viewState']) ?? [];
        if (empty($rows)) {
            $this->logger->info("No rows found for ISSN {$issn} in quadrennium 2021-2024. Falling back to 2017-2020.");
            $eventId = '236';
            $rows = $this->queryEventRows($issn, $eventId, $session['cookieStr'], $session['viewState']) ?? [];
        }

        return [
            'rows' => $rows,
            'jsessionid' => $session['cookies']['JSESSIONID'] ?? null,
            'viewstate' => $session['viewState'],
            'eventid' => $eventId
        ];
    }

    /**
     * GET inicial: cookies de sessão e javax.faces.ViewState.
     *
     * @return array{cookieStr: string, viewState: string, cookies: array<string, string>}|null null em caso de falha
     */
    private function openSession(): ?array
    {
        $response = $this->request(null, null, true);
        if ($response === null) {
            $this->logger->warning('Failed to perform initial GET request to Sucupira.');
            return null;
        }

        // Cookies lidos sem parse_str, que converteria o sinal '+'
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);
        $cookies = [];
        foreach ($matches[1] as $item) {
            $cookieParts = explode('=', $item, 2);
            if (count($cookieParts) === 2) {
                $cookies[trim($cookieParts[0])] = trim($cookieParts[1]);
            }
        }

        $cookieStr = '';
        foreach ($cookies as $name => $value) {
            $cookieStr .= "$name=$value; ";
        }

        if (!preg_match('/name="javax.faces.ViewState"[^>]*value="([^"]+)"/', $response, $m)
            && !preg_match('/value="([^"]+)"[^>]*name="javax.faces.ViewState"/', $response, $m)) {
            $this->logger->warning('Could not find javax.faces.ViewState in Sucupira HTML response.');
            return null;
        }

        return ['cookieStr' => $cookieStr, 'viewState' => $m[1], 'cookies' => $cookies];
    }

    /**
     * Linhas com estrato válido de um evento/quadriênio; null quando a requisição falha.
     *
     * @return list<array{issn: string, title: string, area: string, qualis: string, mother_area: string}>|null
     */
    private function queryEventRows(string $issn, string $eventId, string $cookieStr, string $viewState): ?array
    {
        $payload = http_build_query([
            'form' => 'form',
            'form:evento' => $eventId,
            'form:checkIssn' => 'on',
            'form:issn:issn' => $issn,
            'form:consultar' => 'Consultar',
            'javax.faces.ViewState' => $viewState
        ]);

        $response = $this->request($payload, $cookieStr, false);
        if ($response === null) {
            $this->logger->warning("POST query failed for ISSN {$issn} on Event {$eventId}.");
            return null;
        }

        return self::parseRows($response);
    }

    /**
     * Linhas da tabela de resultado cuja 4ª coluna é um estrato válido (A1-A4, B1-B5, C).
     *
     * @return list<array{issn: string, title: string, area: string, qualis: string, mother_area: string}>
     */
    public static function parseRows(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);

        $result = [];
        foreach ($xpath->query('//table//tr') as $row) {
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

    /**
     * GET (sem payload) ou POST na página de consulta. O certificado do servidor é verificado
     * (padrão do cURL): a resposta entra no cadastro global do Qualis.
     *
     * @return string|null corpo (com cabeçalhos, se pedido) ou null quando o HTTP não é 200
     */
    private function request(?string $payload, ?string $cookieStr, bool $withHeaders): ?string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::BASE_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        if ($withHeaders) {
            curl_setopt($ch, CURLOPT_HEADER, true);
        }

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $headers = [
                'Content-Type: application/x-www-form-urlencoded',
                'Origin: https://sucupira-legado.capes.gov.br',
                'Referer: ' . self::BASE_URL
            ];
            if ($cookieStr) {
                $headers[] = 'Cookie: ' . rtrim($cookieStr, '; ');
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->logger->warning("Sucupira request failed. HTTP Code: {$httpCode}" . ($error !== '' ? " ({$error})" : ''));
            return null;
        }

        return $response;
    }
}
