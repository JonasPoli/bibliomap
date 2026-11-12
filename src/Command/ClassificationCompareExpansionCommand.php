<?php

namespace App\Command;

use App\Entity\BibliometricProject;
use App\Entity\ClassificationGroup;
use App\Service\Classification\ClassificationEngine;
use App\Service\Classification\ClassificationTermExpander;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Compara, sem gravar nada no banco, a classificação de um projeto com a expansão anterior
 * (só rótulos do tesauro) e com a atual (rótulos + variações das palavras-chave).
 */
#[AsCommand(
    name: 'app:classification:compare-expansion',
    description: 'Compara, sem gravar no banco, a classificação de um projeto sem e com as variações das palavras-chave na expansão pelo tesauro.'
)]
class ClassificationCompareExpansionCommand extends Command
{
    private const UNC = 'unc';
    private const SHORT = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClassificationEngine $engine,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projectId', InputArgument::REQUIRED, 'ID do projeto')
            ->addOption('csv', null, InputOption::VALUE_REQUIRED, 'Grava os documentos que mudaram (ID, ano, título, grupo, entrou/saiu, termo) neste arquivo, fora do projeto')
            ->setHelp(<<<'HELP'
Só lê o banco: os resultados gravados do projeto não mudam. Usa CPU como uma classificação completa (duas vezes).
O CSV tem dados do corpus: o caminho tem de ficar fora do projeto (o repositório é público).
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (int) $input->getArgument('projectId');

        $project = $this->em->getRepository(BibliometricProject::class)->find($id);
        if (!$project) {
            $io->error(sprintf('Projeto #%d não encontrado.', $id));

            return Command::FAILURE;
        }

        $csvPath = $input->getOption('csv');
        if ($csvPath !== null && !$this->isOutsideProject($csvPath)) {
            $io->error('O CSV tem dados do corpus: grave-o fora do projeto (o repositório é público).');

            return Command::FAILURE;
        }

        $io->title(sprintf('Comparação da expansão — projeto #%d', $id));

        // Um resultado por vez: de cada cálculo guardamos só a pertinência (grupo → documento → termo),
        // as estatísticas e os termos; as atribuições completas são soltas antes do segundo cálculo.
        $oldResult = $this->engine->calculate($project, ClassificationTermExpander::MODE_THESAURUS_ONLY);
        $before = $this->membership($oldResult['assignments']);
        $old = ['stats' => $oldResult['stats'], 'termMap' => $oldResult['termMap']];
        unset($oldResult);
        gc_collect_cycles();

        $newResult = $this->engine->calculate($project, ClassificationTermExpander::MODE_WITH_VARIATIONS);
        $after = $this->membership($newResult['assignments']);
        $new = ['stats' => $newResult['stats'], 'termMap' => $newResult['termMap'], 'groups' => $newResult['groups']];
        unset($newResult);
        gc_collect_cycles();

        $groups = $this->groupLabels($new['groups']);

        // 1. Uma linha por grupo
        $rows = [];
        $changes = [];
        foreach ($groups as $key => [$name, $type]) {
            $b = $before[$key] ?? [];
            $a = $after[$key] ?? [];
            $in = array_diff_key($a, $b);
            $out = array_diff_key($b, $a);
            $changes[$key] = ['in' => $in, 'out' => $out];
            $rows[] = [$name, $type, count($b), count($a), count($in), count($out)];
        }
        $io->section('Documentos por grupo');
        $io->table(['Grupo', 'Tipo', 'Antes', 'Depois', 'Entram', 'Saem'], $rows);

        // 2. Resumo de cada modo
        $io->section('Resumo');
        $io->table(['Expansão', 'Documentos', 'Ruído', 'Sem grupo', 'Multiclassificados'], [
            ['Só tesauro (anterior)', $old['stats']['total'], $old['stats']['noise'], $old['stats']['unclassified'], $old['stats']['multi']],
            ['Tesauro + variações (atual)', $new['stats']['total'], $new['stats']['noise'], $new['stats']['unclassified'], $new['stats']['multi']],
        ]);

        // 3. Variações que entraram na expansão (e não estavam entre os rótulos do tesauro)
        $io->section('Variações que entraram na expansão');
        $anyVariation = false;
        foreach ($new['groups'] as $group) {
            $gid = $group->getId();
            $added = array_values(array_diff($new['termMap'][$gid] ?? [], $old['termMap'][$gid] ?? []));
            if ($added === []) {
                continue;
            }
            $anyVariation = true;
            $entered = $changes[$gid]['in'] ?? [];
            $byTerm = array_count_values(array_map('strval', array_filter($entered, fn($t) => $t !== null)));
            $lines = [];
            foreach ($added as $variation) {
                $lines[] = sprintf(
                    '%s — %d documento(s) entraram por ela%s',
                    $variation,
                    $byTerm[$variation] ?? 0,
                    mb_strlen($variation) <= self::SHORT ? ' [ATENÇÃO: até 3 caracteres, casa dentro de outras palavras]' : ''
                );
            }
            $io->text(sprintf('<info>%s</info> (%s): %d variação(ões)', $group->getName(), $group->getType(), count($added)));
            $io->listing($lines);
        }
        if (!$anyVariation) {
            $io->text('Nenhuma: os termos dos grupos com o tesauro ligado não têm variações ativas.');
        }

        if ($csvPath !== null) {
            $written = $this->writeCsv($csvPath, $groups, $changes);
            $io->note(sprintf('%d linha(s) gravadas no CSV (dados do corpus: apague depois da análise).', $written));
        }

        $io->success('Comparação concluída. Nada foi gravado no banco.');

        return Command::SUCCESS;
    }

    /**
     * @param array<int, list<array{group: ?ClassificationGroup, term: ?string, unclassified: bool}>> $assignments
     * @return array<int|string, array<int, ?string>> grupo → [documento → termo correspondido]
     */
    private function membership(array $assignments): array
    {
        $map = [];
        foreach ($assignments as $docId => $list) {
            foreach ($list as $a) {
                $key = $a['unclassified'] ? self::UNC : $a['group']?->getId();
                if ($key !== null) {
                    $map[$key][$docId] = $a['term'];
                }
            }
        }

        return $map;
    }

    /**
     * @param ClassificationGroup[] $groups
     * @return array<int|string, array{string, string}>
     */
    private function groupLabels(array $groups): array
    {
        $labels = [];
        foreach ($groups as $g) {
            if ($g->getType() !== ClassificationGroup::TYPE_UNCLASSIFIED) {
                $labels[$g->getId()] = [$g->getName(), $g->getType()];
            }
        }
        $labels[self::UNC] = [ClassificationEngine::UNCLASSIFIED_NAME, ClassificationGroup::TYPE_UNCLASSIFIED];

        return $labels;
    }

    private function isOutsideProject(string $path): bool
    {
        $dir = realpath(dirname($path));
        $project = realpath($this->projectDir) ?: $this->projectDir;

        return $dir !== false && $dir !== $project && !str_starts_with($dir . '/', rtrim($project, '/') . '/');
    }

    /** Ano e título só dos documentos que mudaram (consulta em blocos; o projeto inteiro não fica na memória). */
    private function loadDocuments(array $docIds): array
    {
        $docs = [];
        foreach (array_chunk(array_keys($docIds), 1000) as $chunk) {
            $rows = $this->em->getConnection()->fetchAllAssociative(
                'SELECT id, year, title FROM document WHERE id IN (?)',
                [$chunk],
                [\Doctrine\DBAL\ArrayParameterType::INTEGER]
            );
            foreach ($rows as $r) {
                $docs[(int) $r['id']] = ['year' => $r['year'], 'title' => $r['title']];
            }
        }

        return $docs;
    }

    private function writeCsv(string $path, array $groups, array $changes): int
    {
        $changedIds = [];
        foreach ($changes as $change) {
            $changedIds += $change['in'] + $change['out'];
        }
        $docs = $this->loadDocuments($changedIds);

        $oldUmask = umask(0077);
        $f = fopen($path, 'w');
        umask($oldUmask);
        if ($f === false) {
            throw new \RuntimeException('Não foi possível gravar o CSV.');
        }
        chmod($path, 0600);
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['document_id', 'ano', 'titulo', 'grupo', 'mudanca', 'termo'], ';', '"', '');

        $count = 0;
        foreach ($changes as $key => $change) {
            foreach (['in' => 'entrou', 'out' => 'saiu'] as $side => $label) {
                foreach ($change[$side] as $docId => $term) {
                    fputcsv($f, [
                        $docId,
                        $docs[$docId]['year'] ?? '',
                        $docs[$docId]['title'] ?? '',
                        $groups[$key][0] ?? (string) $key,
                        $label,
                        $term ?? '',
                    ], ';', '"', '');
                    $count++;
                }
            }
        }
        fclose($f);

        return $count;
    }
}
