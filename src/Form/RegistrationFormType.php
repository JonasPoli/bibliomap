<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nome completo',
                'attr' => ['placeholder' => 'Seu nome completo'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'attr' => ['placeholder' => 'seu@email.com'],
            ])
            ->add('institution', TextType::class, [
                'label' => 'Instituição',
                'required' => false,
                'attr' => ['placeholder' => 'Universidade ou instituição (opcional)'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'Senha',
                    'attr' => ['placeholder' => 'Mínimo 8 caracteres'],
                    'constraints' => [
                        new NotBlank(['message' => 'Informe uma senha.']),
                        new Length(['min' => 8, 'minMessage' => 'A senha deve ter ao menos {{ limit }} caracteres.']),
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirmar senha',
                    'attr' => ['placeholder' => 'Repita a senha'],
                ],
                'invalid_message' => 'As senhas não coincidem.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
