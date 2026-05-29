<?php

namespace App\Form;

use App\Entity\BibliometricProject;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BibliometricProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentYear = (int) date('Y');

        $builder
            ->add('title', TextType::class, [
                'label' => 'Título do projeto',
                'attr' => ['placeholder' => 'Ex: Análise bibliométrica sobre Inteligência Artificial em Saúde'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descrição',
                'required' => false,
                'attr' => ['placeholder' => 'Descreva o objetivo geral desta análise...', 'rows' => 3],
            ])
            ->add('researchQuestion', TextareaType::class, [
                'label' => 'Pergunta de pesquisa',
                'required' => false,
                'attr' => ['placeholder' => 'Qual a pergunta central desta revisão?', 'rows' => 2],
            ])
            ->add('searchString', TextareaType::class, [
                'label' => 'String de busca',
                'required' => false,
                'attr' => ['placeholder' => '("artificial intelligence" OR "machine learning") AND ("health" OR "medicine")', 'rows' => 3],
            ])
            ->add('databaseSources', ChoiceType::class, [
                'label' => 'Bases de dados utilizadas',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'Scopus' => 'scopus',
                    'Web of Science' => 'wos',
                    'Lens.org' => 'lens',
                    'PubMed' => 'pubmed',
                    'OpenAlex' => 'openalex',
                    'Crossref' => 'crossref',
                    'Outra' => 'other',
                ],
            ])
            ->add('startYear', IntegerType::class, [
                'label' => 'Ano inicial',
                'required' => false,
                'attr' => ['min' => 1900, 'max' => $currentYear, 'placeholder' => '2000'],
            ])
            ->add('endYear', IntegerType::class, [
                'label' => 'Ano final',
                'required' => false,
                'attr' => ['min' => 1900, 'max' => $currentYear, 'placeholder' => (string) $currentYear],
            ])
            ->add('visibility', ChoiceType::class, [
                'label' => 'Visibilidade',
                'choices' => [
                    'Privado (somente você)' => 'private',
                    'Público (qualquer pessoa com o link)' => 'public',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BibliometricProject::class,
        ]);
    }
}
