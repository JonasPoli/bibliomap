<?php

namespace App\Command;

use App\Service\Import\TextNormalizer;
use App\Service\Thesaurus\EntityMergeService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Autores importados por versões antigas guardam normalized_name em outro formato
 * ("chen, ying", "anna durai a.") e ficaram como pendentes (status 0) sem motivo.
 * A importação atual não os reconhece e cria um segundo cadastro para o mesmo nome,
 * dividindo documentos, citações e h-index do autor.
 *
 * Recalcula a chave com a regra atual da importação, funde cadastros que passam a ter
 * a mesma chave (no que tiver mais documentos) e reavalia o status dos legados.
 */
#[AsCommand(
    name: 'app:authors:renormalize',
    description: 'Recalcula nomes normalizados de autores, funde duplicados e reavalia pendências legadas',
)]
class RenormalizeAuthorsCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
        private readonly EntityMergeService $mergeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Aplica as alterações (sem esta opção apenas simula)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');
        $normalizer = new TextNormalizer();

        $docCounts = array_map('intval', $this->conn->fetchAllKeyValue(
            'SELECT author_identity_id, COUNT(DISTINCT document_id) FROM document_author GROUP BY author_identity_id'
        ));

        $authors = [];
        $groups = [];
        foreach ($this->conn->iterateAssociative('SELECT id, preferred_name, normalized_name, status, review_reasons FROM author_identity') as $row) {
            $res = $normalizer->normalizeAuthor((string) $row['preferred_name']);
            $key = $res['valid'] ? mb_substr($res['normalized'], 0, 255) : (string) $row['normalized_name'];
            $id = (int) $row['id'];
            $authors[$id] = $row + ['new_key' => $key, 'res' => $res, 'docs' => $docCounts[$id] ?? 0];
            $groups[$key][] = $id;
        }

        $dupGroups = array_filter($groups, fn($ids) => count($ids) > 1);
        $toMerge = array_sum(array_map(fn($ids) => count($ids) - 1, $dupGroups));
        $keyChanges = count(array_filter($authors, fn($a) => $a['new_key'] !== $a['normalized_name']));
        $legacy = array_filter($authors, fn($a) => (int) $a['status'] === 0 && $a['review_reasons'] === null);
        $legacyOk = count(array_filter($legacy, fn($a) => $a['res']['valid'] && !$a['res']['needs_review']));

        $io->table(['', 'Quantidade'], [
            ['Autores', count($authors)],
            ['Nomes com mesma chave (grupos)', count($dupGroups)],
            ['Cadastros que serão fundidos', $toMerge],
            ['Chaves normalizadas a atualizar', $keyChanges],
            ['Pendentes legados (sem motivo)', count($legacy)],
            ['  → passam a validados', $legacyOk],
            ['  → continuam pendentes, com motivo', count($legacy) - $legacyOk],
        ]);

        if (!$execute) {
            $io->note('Simulação. Rode com --execute para aplicar (faça backup do banco antes).');
            return Command::SUCCESS;
        }

        $now = date('Y-m-d H:i:s');
        $deleted = [];
        $io->progressStart(count($dupGroups));
        foreach ($dupGroups as $ids) {
            // Master: mais documentos; empate → validado; depois o id mais antigo
            usort($ids, fn($a, $b) => [$authors[$b]['docs'], (int) $authors[$b]['status'], -$b] <=> [$authors[$a]['docs'], (int) $authors[$a]['status'], -$a]);
            $masterId = array_shift($ids);

            $this->conn->transactional(function () use ($ids, $masterId, $authors, $now, &$deleted) {
                foreach ($ids as $sourceId) {
                    $this->mergeService->remapAuthorLinks($sourceId, $masterId);
                    $this->conn->executeStatement('UPDATE author_name_variant SET author_identity_id = ? WHERE author_identity_id = ?', [$masterId, $sourceId]);
                    // Identificadores externos que o master ainda não tem
                    $this->conn->executeStatement(
                        'UPDATE author_external_identifier s SET s.author_identity_id = ?
                         WHERE s.author_identity_id = ? AND NOT EXISTS (
                             SELECT 1 FROM (SELECT provider, identifier FROM author_external_identifier WHERE author_identity_id = ?) m
                             WHERE m.provider = s.provider AND m.identifier = s.identifier)',
                        [$masterId, $sourceId, $masterId]
                    );
                    $name = (string) $authors[$sourceId]['preferred_name'];
                    if ($name !== (string) $authors[$masterId]['preferred_name']) {
                        $this->conn->insert('author_name_variant', [
                            'author_identity_id' => $masterId,
                            'original_name'      => $name,
                            'display_name'       => $name,
                            'normalized_name'    => $authors[$sourceId]['new_key'],
                            'source'             => 'merge',
                            'confidence'         => 1.0,
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ]);
                    }
                    $this->conn->delete('author_identity', ['id' => $sourceId]);
                    $deleted[$sourceId] = true;
                }
            });
            $io->progressAdvance();
        }
        $io->progressFinish();

        $updated = 0;
        foreach (array_chunk(array_keys($authors), 2000) as $chunk) {
            $this->conn->transactional(function () use ($chunk, $authors, $deleted, $now, &$updated) {
                foreach ($chunk as $id) {
                    if (isset($deleted[$id])) {
                        continue;
                    }
                    $a = $authors[$id];
                    $set = [];
                    if ($a['new_key'] !== $a['normalized_name']) {
                        $set['normalized_name'] = $a['new_key'];
                    }
                    if ((int) $a['status'] === 0 && $a['review_reasons'] === null && $a['res']['valid']) {
                        $set['status'] = $a['res']['needs_review'] ? 0 : 1;
                        $set['review_reasons'] = $a['res']['review_reasons'] ? implode(',', $a['res']['review_reasons']) : null;
                    }
                    if ($set) {
                        $this->conn->update('author_identity', $set + ['updated_at' => $now], ['id' => $id]);
                        $updated++;
                    }
                }
            });
        }

        $io->success(sprintf('%d cadastros fundidos; %d autores atualizados.', count($deleted), $updated));

        return Command::SUCCESS;
    }
}
