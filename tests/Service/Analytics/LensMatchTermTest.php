<?php

namespace App\Tests\Service\Analytics;

use App\Controller\ReportController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A mineração das lentes usa str_contains antes da regex: o resultado não pode mudar. */
class LensMatchTermTest extends TestCase
{
    private function match(string $text, string $term, bool $fast): bool
    {
        $controller = (new \ReflectionClass(ReportController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'matchTerm');

        // Como o lote monta os textos: strtolower (só ASCII) para a regex, mb_strtolower para a pré-checagem
        $text = strtolower($text);
        $term = strtolower($term);

        return $fast
            ? $method->invoke($controller, $text, $term, mb_strtolower($text), mb_strtolower($term))
            : $method->invoke($controller, $text, $term);
    }

    #[DataProvider('cases')]
    public function testSameResultWithAndWithoutPreCheck(string $text, string $term, bool $expected): void
    {
        $this->assertSame($expected, $this->match($text, $term, false), 'só a regex');
        $this->assertSame($expected, $this->match($text, $term, true), 'com a pré-checagem');
    }

    public static function cases(): array
    {
        return [
            'palavra inteira'                 => ['The Actor-Network Theory in rural extension', 'actor-network', true],
            'dentro de outra palavra'         => ['the factory floor', 'actor', false],
            'maiúsculas no texto'             => ['BRUNO LATOUR e a sociologia', 'latour', true],
            'acento minúsculo igual'          => ['a ética na pesquisa', 'ética', true],
            'acento maiúsculo no texto'       => ['A ÉTICA NA PESQUISA', 'ética', true],
            'acento maiúsculo no termo'       => ['a ética na pesquisa', 'ÉTICA', true],
            'sem o termo'                     => ['agricultura de precisão', 'foucault', false],
            'termo com hífen no fim da frase' => ['teoria do ator-rede.', 'ator-rede', true],
            'cedilha e til'                   => ['EDUCAÇÃO POPULAR', 'educação', true],
            'termo vazio'                     => ['qualquer texto', '', false],
        ];
    }
}
