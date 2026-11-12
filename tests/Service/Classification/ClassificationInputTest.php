<?php

namespace App\Tests\Service\Classification;

use App\Controller\ClassificationController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Validação da cor e do ícone do grupo (entram em atributos de estilo e classe nas telas). */
class ClassificationInputTest extends TestCase
{
    #[DataProvider('colors')]
    public function testSanitizeColor(string $input, string $expected): void
    {
        $this->assertSame($expected, ClassificationController::sanitizeColor($input));
    }

    public static function colors(): array
    {
        return [
            'hex válido'                 => ['#a855F7', '#a855F7'],
            'hex com espaços'            => ['  #4f8ef7 ', '#4f8ef7'],
            'vazio vira o padrão'        => ['', '#4f8ef7'],
            'hex curto não vale'         => ['#fff', '#4f8ef7'],
            'nome de cor não vale'       => ['red', '#4f8ef7'],
            'injeção de atributo'        => ['#ffffff" onmouseover="alert(1)', '#4f8ef7'],
            'injeção de CSS'             => ['#ffffff;background:url(//x)', '#4f8ef7'],
            'tag'                        => ['<script>alert(1)</script>', '#4f8ef7'],
            'quebra de linha no fim'     => ["#ffffff\n", '#ffffff'],
            'hex com 8 dígitos'          => ['#ffffffff', '#4f8ef7'],
        ];
    }

    #[DataProvider('icons')]
    public function testSanitizeIcon(string $input, string $expected): void
    {
        $this->assertSame($expected, ClassificationController::sanitizeIcon($input));
    }

    public static function icons(): array
    {
        return [
            'ícone válido'            => ['bi-journal-text', 'bi-journal-text'],
            'com números'             => ['bi-1-circle', 'bi-1-circle'],
            'vazio vira o padrão'     => ['', 'bi-collection'],
            'sem prefixo'             => ['journal', 'bi-collection'],
            'maiúsculas não valem'    => ['bi-Journal', 'bi-collection'],
            'injeção de atributo'     => ['bi-x" onclick="alert(1)', 'bi-collection'],
            'espaço (2ª classe)'      => ['bi-x fa-spin', 'bi-collection'],
            'tag'                     => ['bi-<img src=x>', 'bi-collection'],
        ];
    }
}
