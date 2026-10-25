<?php

namespace App\Tests\Service\Classification;

use App\Controller\ClassificationController;
use App\Service\Classification\ClassificationEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClassificationFiltersTest extends TestCase
{
    #[DataProvider('authorsCases')]
    public function testParseAuthorsFilter(string $raw, array $expected): void
    {
        $this->assertSame($expected, ClassificationController::parseAuthorsFilter($raw));
    }

    public static function authorsCases(): array
    {
        return [
            'vazio'                      => ['   ', []],
            'ponto e vírgula'            => ['Molin; Amaral; Smith', ['Molin', 'Amaral', 'Smith']],
            'nome com inicial (;)'       => ['Silva, J; Molin, JP', ['Silva, J', 'Molin, JP']],
            'um por linha'               => ["Silva, J\nMolin\r\nAmaral", ['Silva, J', 'Molin', 'Amaral']],
            'só um nome com ; final'     => ['Silva, J;', ['Silva, J']],
            'legado só vírgulas'         => ['Molin, Amaral, Smith', ['Molin', 'Amaral', 'Smith']],
            'legado com inicial'         => ['Silva, J', ['Silva, J']],
            'legado iniciais com ponto'  => ['Silva, J.R., Molin', ['Silva, J.R.', 'Molin']],
            'legado sobrenome curto'     => ['Li, Wu', ['Li', 'Wu']],
            'sobra de separadores'       => [';; Molin ;;', ['Molin']],
        ];
    }

    #[DataProvider('continentCases')]
    public function testContinentMatches(string $group, array $doc, bool $expected): void
    {
        $this->assertSame($expected, ClassificationEngine::continentMatches($group, $doc));
    }

    public static function continentCases(): array
    {
        return [
            'acento maiúsculo (Ásia)'        => ['Ásia', ['ásia'], true],
            'acento maiúsculo (África)'      => ['África', ['áfrica', 'europa'], true],
            'transcontinental casa Europa'   => ['Europa', ['europa/ásia'], true],
            'transcontinental casa Ásia'     => ['Ásia', ['europa/ásia'], true],
            'Europa/Ásia exato'              => ['Europa/Ásia', ['europa/ásia'], true],
            'Europa/Ásia não pega Europa'    => ['Europa/Ásia', ['europa'], false],
            'América Central ≠ do Norte'     => ['América do Norte', ['américa central'], false],
            'América Central exata'          => ['América Central', ['américa central'], true],
            'sem continente no documento'    => ['Europa', [], false],
        ];
    }
}
