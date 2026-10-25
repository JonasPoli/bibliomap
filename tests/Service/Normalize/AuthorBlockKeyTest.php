<?php

namespace App\Tests\Service\Normalize;

use App\Service\Normalize\NormalizationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuthorBlockKeyTest extends TestCase
{
    #[DataProvider('names')]
    public function testBlockKey(string $name, string $expected): void
    {
        $this->assertSame($expected, NormalizationService::authorBlockKey($name));
    }

    public static function names(): array
    {
        return [
            'WoS iniciais'            => ['Silva, J', 'silv|j'],
            'WoS mais iniciais'       => ['Silva, JA', 'silv|j'],
            'iniciais com ponto'      => ['Silva, J. A.', 'silv|j'],
            'prenome completo'        => ['Silva, João Antônio', 'silv|j'],
            'acento no sobrenome'     => ['Gonçalves, M. A.', 'gonc|m'],
            'caixa alta'              => ['GONCALVES, MA', 'gonc|m'],
            'sobrenome composto'      => ['ten Berge, Hein F. M', 'tenb|h'],
            'hífen'                   => ['Lamas-Aicon, Macarena', 'lama|m'],
            'Scopus sem vírgula'      => ['Silva J.A.', 'silv|j'],
            'iniciais no fim'         => ['Silva JA', 'silv|j'],
            'Lens prenome sobrenome'  => ['João Silva', 'silv|j'],
            'nome único'              => ['Silva', 'silv|'],
        ];
    }

    public function testInitialVariantsShareBlock(): void
    {
        // Antes a chave usava a última palavra como sobrenome: "Silva, J" (j|s) e "Silva, JA" (ja|s) nunca eram comparados
        $this->assertSame(
            NormalizationService::authorBlockKey('Alexander, B'),
            NormalizationService::authorBlockKey('Alexander, BH')
        );
        $this->assertNotSame(
            NormalizationService::authorBlockKey('Silva, J'),
            NormalizationService::authorBlockKey('Silva, M')
        );
    }
}
