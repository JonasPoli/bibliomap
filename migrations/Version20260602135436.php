<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create theoretical_lens table and seed it with 13 classical and contemporary CTS/Sociology theorists.
 */
final class Version20260602135436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create theoretical_lens table and seed 13 CTS/Sociology theorists';
    }

    public function up(Schema $schema): void
    {
        // 1. Create the table
        $this->addSql('CREATE TABLE theoretical_lens (
            id INT AUTO_INCREMENT NOT NULL, 
            name VARCHAR(255) NOT NULL, 
            category VARCHAR(255) NOT NULL, 
            research_field VARCHAR(255) NOT NULL, 
            terms JSON NOT NULL, 
            description LONGTEXT NOT NULL, 
            icon VARCHAR(100) DEFAULT NULL, 
            color VARCHAR(50) DEFAULT NULL, 
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // 2. Seed the 13 default theorists
        $theorists = [
            [
                'name' => 'Bruno Latour',
                'category' => 'Teoria Ator-Rede e Construtivismo',
                'researchField' => 'CTS',
                'terms' => ['latour', 'actor-network', 'ator-rede', 'non-human', 'não-humano', 'translation theory', 'teoria da tradução', 'actant', 'actante', 'simetria'],
                'description' => 'Foca nas redes sociotécnicas formadas simetricamente por atores humanos e não humanos. Ideal para analisar o chatbot (ator não humano) e o extensionista rural (ator humano) agindo em coprodução de conhecimento.',
                'icon' => 'bi-diagram-3-fill',
                'color' => '#a855f7',
            ],
            [
                'name' => 'Michel Callon',
                'category' => 'Teoria Ator-Rede e Construtivismo',
                'researchField' => 'CTS',
                'terms' => ['callon', 'sociotechnic', 'sociotécnico', 'problematization', 'problematização', 'interessement', 'interessamento', 'enrolment', 'recrutamento'],
                'description' => 'Estuda os processos de tradução de interesses e controvérsias em redes sociotécnicas. Ajuda a investigar como os extensionistas rurais aceitam, negociam ou resistem à introdução de um chatbot na sua rotina de trabalho.',
                'icon' => 'bi-arrow-left-right',
                'color' => '#4f8ef7',
            ],
            [
                'name' => 'Wiebe Bijker',
                'category' => 'Teoria Ator-Rede e Construtivismo',
                'researchField' => 'CTS',
                'terms' => ['bijker', 'social construction of technology', 'construção social da tecnologia', 'scot', 'relevant social groups', 'grupos sociais relevantes', 'interpretative flexibility', 'flexibilidade interpretativa', 'technological frame', 'quadro tecnológico'],
                'description' => 'Trabalha na Construção Social da Tecnologia (SCOT). Excelente para entender a "flexibilidade interpretativa" do chatbot: o que a tecnologia significa para o desenvolvedor vs. o que ela significa para o extensionista do campo.',
                'icon' => 'bi-shuffle',
                'color' => '#5da5da',
            ],
            [
                'name' => 'Trevor Pinch',
                'category' => 'Teoria Ator-Rede e Construtivismo',
                'researchField' => 'CTS',
                'terms' => ['pinch', 'social construction of facts', 'construção social dos fatos', 'stabilization', 'estabilização', 'closure', 'fechamento interpretativo'],
                'description' => 'Estuda a construção social dos fatos científicos e como as controvérsias em torno de novas tecnologias se estabilizam e fecham na sociedade.',
                'icon' => 'bi-lock-fill',
                'color' => '#3d5a80',
            ],
            [
                'name' => 'Michel Foucault',
                'category' => 'Sociologia e Filosofia Crítica',
                'researchField' => 'CTS',
                'terms' => ['foucault', 'power relations', 'relações de poder', 'discourse analysis', 'análise do discurso', 'biopower', 'biopoder', 'surveillance', 'vigilância', 'panopticon', 'panóptico', 'archeology of knowledge', 'arqueologia do saber'],
                'description' => 'Analisa o poder descentralizado, o discurso e formas de controle. Ideal se a sua dissertação pretende discutir como o chatbot atua como um dispositivo de poder, vigilância do trabalho ou direcionamento do conhecimento rural.',
                'icon' => 'bi-eye-fill',
                'color' => '#f59e0b',
            ],
            [
                'name' => 'Pierre Bourdieu',
                'category' => 'Sociologia e Filosofia Crítica',
                'researchField' => 'CTS',
                'terms' => ['bourdieu', 'habitus', 'social field', 'campo social', 'social capital', 'capital social', 'cultural capital', 'capital cultural', 'symbolic violence', 'violência simbólica'],
                'description' => 'Fornece a ótica de campo, habitus e capitais. Útil para investigar se os extensionistas com maior capital tecnológico ou cultural incorporam o chatbot de forma distinta, alterando as relações de poder no campo institucional da extensão rural.',
                'icon' => 'bi-award-fill',
                'color' => '#f28f3b',
            ],
            [
                'name' => 'Karl Marx',
                'category' => 'Sociologia e Filosofia Crítica',
                'researchField' => 'CTS',
                'terms' => ['marx', 'capitalism', 'capitalismo', 'alienation', 'alienação', 'means of production', 'meios de produção', 'proletarianization', 'proletarização', 'labor force', 'força de trabalho'],
                'description' => 'Foca no trabalho, exploração e tecnologia como meio de controle da força produtiva. Ajuda a discutir se a automação da extensão rural via IA representa uma forma de alienação ou de otimização das forças produtivas agrícolas.',
                'icon' => 'bi-hammer',
                'color' => '#ef4444',
            ],
            [
                'name' => 'Jürgen Habermas',
                'category' => 'Sociologia e Filosofia Crítica',
                'researchField' => 'CTS',
                'terms' => ['habermas', 'communicative action', 'ação comunicativa', 'public sphere', 'esfera pública', 'communicative rationality', 'racionalidade comunicativa'],
                'description' => 'Analisa a ação comunicativa e racionalidade do diálogo. Ideal para discutir a qualidade e a ética da comunicação entre o extensionista (humano) e o chatbot (sistema automatizado) a nível linguístico.',
                'icon' => 'bi-chat-quote-fill',
                'color' => '#9e2a2b',
            ],
            [
                'name' => 'Renato Dagnino',
                'category' => 'Pensamento Latino-Americano em CTS',
                'researchField' => 'CTS',
                'terms' => ['dagnino', 'sociotechnical adequacy', 'adequação sociotécnica', 'social technology', 'tecnologia social', 'technological decision', 'decisão tecnológica', 'popular solidarity economy', 'economia solidária'],
                'description' => 'Principal expoente brasileiro do PLACTS. Discute a "Adequação Sociotécnica" das tecnologias. Essencial para analisar se um chatbot (tecnologia convencional/norte-americana) pode ser readequado sociotécnica e localmente para apoiar a agricultura familiar brasileira e assentamentos.',
                'icon' => 'bi-brightness-high-fill',
                'color' => '#10b981',
            ],
            [
                'name' => 'Amílcar Herrera',
                'category' => 'Pensamento Latino-Americano em CTS',
                'researchField' => 'CTS',
                'terms' => ['herrera', 'scientific policy', 'política científica', 'explicit policy', 'política explícita', 'implicit policy', 'política implícita', 'latin american scientific project', 'projeto científico latino-americano'],
                'description' => 'Foca nas políticas de ciência e tecnologia implícitas vs. explícitas nos países em desenvolvimento. Excelente se a sua pesquisa avalia se a adoção de IA na extensão rural atende a uma política de desenvolvimento nacional ou apenas a interesses corporativos externos.',
                'icon' => 'bi-bank',
                'color' => '#2b9348',
            ],
            [
                'name' => 'Oscar Varsavsky',
                'category' => 'Pensamento Latino-Americano em CTS',
                'researchField' => 'CTS',
                'terms' => ['varsavsky', 'scientific rebellion', 'rebeldia científica', 'standard science', 'ciência padronizada', 'national science', 'ciência nacional', 'politicized science', 'ciência politizada'],
                'description' => 'Crítica à "ciência padrão" e propõe uma ciência com compromisso político social focada em resolver os problemas do povo e do território. Perfeito para defender a criação de um chatbot voltado a problemas rurais locais específicos de pequenos produtores, contra a IA comercial genérica.',
                'icon' => 'bi-shield-fire',
                'color' => '#80b918',
            ],
            [
                'name' => 'Thomas Kuhn',
                'category' => 'Filosofia e História da Ciência',
                'researchField' => 'CTS',
                'terms' => ['kuhn', 'scientific paradigm', 'paradigma científico', 'scientific revolution', 'revolução científica', 'normal science', 'ciência normal', 'incommensurability', 'incomensurabilidade'],
                'description' => 'Foca em paradigmas e revoluções. Ajuda a analisar se a introdução de inteligência artificial generativa (chatbots) na extensão rural representa uma "ruptura de paradigma" no modo clássico de transferência de tecnologia.',
                'icon' => 'bi-infinity',
                'color' => '#f0883e',
            ],
            [
                'name' => 'Donna Haraway',
                'category' => 'Filosofia e História da Ciência',
                'researchField' => 'CTS',
                'terms' => ['haraway', 'cyborg', 'ciborgue', 'situated knowledges', 'saberes localizados', 'companion species', 'espécies companheiras', 'feminist epistemology', 'epistemologia feminista'],
                'description' => 'Traz a perspectiva de saberes localizados e a figura do ciborgue (híbrido humano-máquina). Excelente para discutir a simbiose entre o extensionista e o chatbot como uma "entidade híbrida" geradora de saberes contextualizados e adaptados à realidade rural.',
                'icon' => 'bi-gender-female',
                'color' => '#e0aaff',
            ],
        ];

        foreach ($theorists as $t) {
            $termsJson = json_encode($t['terms'], JSON_UNESCAPED_UNICODE);
            $this->addSql(
                'INSERT INTO theoretical_lens (name, category, research_field, terms, description, icon, color) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $t['name'],
                    $t['category'],
                    $t['researchField'],
                    $termsJson,
                    $t['description'],
                    $t['icon'],
                    $t['color'],
                ]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE theoretical_lens');
    }
}
