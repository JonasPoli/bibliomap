<?php

namespace App\Tests\Service\Cache;

use App\Service\Cache\DataVersionTracker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** "Autoridade suja": quais gravações pedem o recálculo do id efetivo (tarefa 1.5 do tesauro-em-camadas). */
class DataVersionTrackerAuthorityTest extends TestCase
{
    public static function sqlProvider(): array
    {
        return [
            'update de keyword' => ['UPDATE keyword SET keyword_concept_id = ? WHERE id = ?', true],
            'update de keyword com crase' => ['UPDATE `keyword` SET thesaurus_concept_id = NULL', true],
            'update de keyword com junção' => ['UPDATE keyword k JOIN thesaurus_label l ON l.id = 1 SET k.thesaurus_concept_id = 3', true],
            'delete de keyword' => ['DELETE FROM keyword WHERE id = ?', true],
            'insert em keyword NÃO marca' => ['INSERT INTO keyword (keyword_original) VALUES (?)', false],
            'insert ignore em keyword NÃO marca' => ['INSERT IGNORE INTO keyword (keyword_original) VALUES (?)', false],
            'insert em authority_mapping' => ['INSERT INTO authority_mapping (a) VALUES (1)', true],
            'update de authority_mapping' => ['UPDATE authority_mapping SET target_id = 1', true],
            'delete de authority_mapping' => ['DELETE FROM authority_mapping WHERE id = 1', true],
            'delete de thesaurus_concept' => ['DELETE FROM thesaurus_concept WHERE id = 1', true],
            'update de thesaurus_concept sem vínculo' => ['UPDATE thesaurus_concept SET preferred_label = ? WHERE id = ?', false],
            'label que muda concept_id' => ['UPDATE thesaurus_label SET concept_id = ? WHERE id = ?', true],
            'label que só muda o texto' => ['UPDATE thesaurus_label SET label = ? WHERE id = ?', false],
            'select' => ['SELECT * FROM keyword', false],
            'document_keyword não é keyword' => ['UPDATE document_keyword SET original_term = ? WHERE id = 1', false],
            'junção em document_keyword que cita keyword' => ['UPDATE document_keyword dk JOIN keyword k ON k.id = dk.keyword_id SET dk.keyword_id = 5', true],
        ];
    }

    #[DataProvider('sqlProvider')]
    public function testMarcacao(string $sql, bool $suja): void
    {
        $this->assertSame($suja, DataVersionTracker::isAuthoritySensitiveWrite($sql));
    }

    public function testRastreadorMarcaEmUpdateDeKeywordMasNaoEmInsert(): void
    {
        $t = new DataVersionTracker();
        $t->statementExecuted('INSERT INTO keyword (keyword_original) VALUES (?)');
        $this->assertFalse($t->isAuthorityDirty());
        $t->statementExecuted('UPDATE keyword SET status = 0 WHERE id = 1');
        $this->assertTrue($t->isAuthorityDirty());
        $t->clearAuthorityDirty();
        $this->assertFalse($t->isAuthorityDirty());
    }

    public function testGravacaoSuspensaNaoMarcaNada(): void
    {
        $t = new DataVersionTracker();
        $t->suspend();
        $t->statementExecuted('UPDATE keyword SET status = 0 WHERE id = 1');
        $t->statementExecuted('UPDATE document_keyword SET effective_keyword_id = 1 WHERE id = 1');
        $t->resume();
        $this->assertFalse($t->isAuthorityDirty());
        $this->assertFalse($t->isDirty());
        $t->markDataChanged();
        $this->assertTrue($t->isDirty());
    }

    public function testTransacaoAbertaFicaVisivelParaOSubscriber(): void
    {
        $t = new DataVersionTracker();
        $t->statementExecuted('START TRANSACTION');
        $this->assertTrue($t->isInTransaction());
        $t->statementExecuted('UPDATE authority_mapping SET target_id = 2');
        $this->assertTrue($t->isAuthorityDirty());
        $t->statementExecuted('ROLLBACK');
        $this->assertFalse($t->isInTransaction());
    }
}
