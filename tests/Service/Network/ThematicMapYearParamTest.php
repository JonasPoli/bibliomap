<?php

namespace App\Tests\Service\Network;

use App\Controller\ThematicMapController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThematicMapYearParamTest extends TestCase
{
    #[DataProvider('years')]
    public function testYearParam(mixed $raw, ?int $expected): void
    {
        $this->assertSame($expected, ThematicMapController::yearParam($raw));
    }

    public static function years(): array
    {
        return [
            'ano comum'            => ['2018', 2018],
            'inteiro'              => [2020, 2020],
            'vazio = sem limite'   => ['', null],
            'nulo = sem limite'    => [null, null],
            'texto'                => ['abc', null],
            'ano absurdo'          => ['99', null],
            'ano futuro absurdo'   => ['9999', null],
            'decimal'              => ['2018.7', 2018],
        ];
    }
}
