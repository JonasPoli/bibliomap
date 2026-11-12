<?php

namespace App\Command;

use App\Service\Import\TextNormalizer;
use App\Service\Authority\AuthorityMappingException;
use App\Service\Authority\AuthorityMappingService;
use Doctrine\DBAL\ArrayParameterType;
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
 * Recalcula a chave com a regra atual da importação, agrupa cadastros que passam a ter
 * a mesma chave no que tiver mais documentos (AuthorityMappingService, escopo global, origem 'command':
 * nada é apagado nem repontuado) e reavalia o status dos legados.
 */
#[AsCommand(
    name: 'app:authors:renormalize',
    description: 'Recalcula nomes normalizados de autores, agrupa duplicados e reavalia pendências legadas',
)]
class RenormalizeAuthorsCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
        private readonly AuthorityMappingService $mappingService,
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

        // Uma passada com cursor: guarda só as chaves (para achar duplicados) e as alterações
        // necessárias, e não a linha de cada um dos ~150 mil autores (estourava os 256 MB do CLI).
        $groups = [];
        $updates = [];
        $total = 0;
        $keyChanges = 0;
        $legacyCount = 0;
        $legacyOk = 0;
        foreach ($this->conn->iterateAssociative('SELECT id, preferred_name, normalized_name, status, review_reasons FROM author_identity') as $row) {
            $res = $normalizer->normalizeAuthor((string) $row['preferred_name']);
            $key = $res['valid'] ? mb_substr($res['normalized'], 0, 255) : (string) $row['normalized_name'];
            $id = (int) $row['id'];
            $total++;
            $groups[$key][] = $id;

            $set = [];
            if ($key !== $row['normalized_name']) {
                $keyChanges++;
                $set['normalized_name'] = $key;
            }
            if ((int) $row['status'] === 0 && $row['review_reasons'] === null) {
                $legacyCount++;
                if ($res['valid']) {
                    if (!$res['needs_review']) {
                        $legacyOk++;
                    }
                    $set['status'] = $res['needs_review'] ? 0 : 1;
                    $set['review_reasons'] = $res['review_reasons'] ? implode(',', $res['review_reasons']) : null;
                }
            }
            if ($set) {
                $updates[$id] = $set;
            }
        }

        $dupGroups = array_filter($groups, fn($ids) => count($ids) > 1);
        unset($groups);
        $toMerge = array_sum(array_map(fn($ids) => count($ids) - 1, $dupGroups));

        $io->table(['', 'Quantidade'], [
            ['Autores', $total],
            ['Nomes com mesma chave (grupos)', count($dupGroups)],
            ['Cadastros que serão fundidos', $toMerge],
            ['Chaves normalizadas a atualizar', $keyChanges],
            ['Pendentes legados (sem motivo)', $legacyCount],
            ['  → passam a validados', $legacyOk],
            ['  → continuam pendentes, com motivo', $legacyCount - $legacyOk],
        ]);

        if (!$execute) {
            $io->note('Simulação. Rode com --execute para aplicar (faça backup do banco antes).');
            return Command::SUCCESS;
        }

        $now = date('Y-m-d H:i:s');
        $grouped = [];
        $refused = 0;
        $io->progressStart(count($dupGroups));
        foreach ($dupGroups as $key => $ids) {
            // Só os autores do grupo são lidos de novo (nome e situação)
            $authors = [];
            foreach ($this->conn->fetchAllAssociative(
                'SELECT id, preferred_name, status FROM author_identity WHERE id IN (?)',
                [$ids],
                [ArrayParameterType::INTEGER]
            ) as $row) {
                $authors[(int) $row['id']] = $row + ['docs' => $docCounts[(int) $row['id']] ?? 0];
            }
            $ids = array_keys($authors);
            if (count($ids) < 2) {
                $io->progressAdvance();
                continue;
            }

            // Master: mais documentos; empate → validado; depois o id mais antigo
            usort($ids, fn($a, $b) => [$authors[$b]['docs'], (int) $authors[$b]['status'], -$b] <=> [$authors[$a]['docs'], (int) $authors[$a]['status'], -$a]);
            $masterId = array_shift($ids);
            $newKey = (string) $key;

            // Agrupa cada duplicado no principal (global, origem 'command'), sem apagar identidade, variação,
            // identificador externo nem vínculo: o principal passa a valer nas análises e o desfazer fica no admin.
            foreach ($ids as $sourceId) {
                try {
                    $this->mappingService->merge(
                        AuthorityMappingService::ENTITY_AUTHOR,
                        $sourceId,
                        $masterId,
                        AuthorityMappingService::SCOPE_GLOBAL,
                        0,
                        'command',
                        null,
                        'app:authors:renormalize'
                    );
                    $grouped[$sourceId] = true;
                } catch (AuthorityMappingException $e) {
                    $refused++;
                }
            }
            $io->progressAdvance();
        }
        $io->progressFinish();

        $updated = 0;
        foreach (array_chunk(array_keys($updates), 2000) as $chunk) {
            $this->conn->transactional(function () use ($chunk, $updates, $grouped, $now, &$updated) {
                foreach ($chunk as $id) {
                    if (isset($grouped[$id])) {
                        continue;
                    }
                    $this->conn->update('author_identity', $updates[$id] + ['updated_at' => $now], ['id' => $id]);
                    $updated++;
                }
            });
        }

        $io->success(sprintf('%d cadastros agrupados (nenhum apagado); %d autores atualizados.', count($grouped), $updated)
            . ($refused > 0 ? sprintf(' %d agrupamento(s) recusado(s) pelo serviço (laço ou já agrupado em outro destino).', $refused) : ''));

        return Command::SUCCESS;
    }
}
