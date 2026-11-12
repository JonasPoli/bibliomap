<?php

namespace App\Service\Authority;

/**
 * Recusa de um agrupamento (laço, auto-mapeamento, origem já agrupada em outro destino, identidade inexistente).
 * A mensagem já vem em pt-BR e pode ser mostrada ao usuário como está.
 */
class AuthorityMappingException extends \DomainException
{
}
