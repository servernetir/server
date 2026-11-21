<?php

namespace App\Services\Mcp;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;

/** Keep Passport's client audience first, and bind the JWT to the MCP resource. */
final class ResourceAccessToken extends \Laravel\Passport\Bridge\AccessToken
{
    private CryptKeyInterface $resourceSigningKey;

    public function setPrivateKey(#[\SensitiveParameter] CryptKeyInterface $privateKey): void
    {
        parent::setPrivateKey($privateKey);
        $this->resourceSigningKey = $privateKey;
    }

    public function toString(): string
    {
        $key = $this->resourceSigningKey;
        $jwt = Configuration::forAsymmetricSigner(new Sha256(),
            InMemory::plainText($key->getKeyContents(), $key->getPassPhrase() ?? ''), InMemory::plainText('unused'));
        return $jwt->builder()->issuedBy(config('mcp_admin.origin'))
            ->permittedFor($this->getClient()->getIdentifier(), config('mcp_admin.resource'))
            ->identifiedBy($this->getIdentifier())->issuedAt(new DateTimeImmutable())
            ->canOnlyBeUsedAfter(new DateTimeImmutable())->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier() ?? $this->getClient()->getIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->getToken($jwt->signer(), $jwt->signingKey())->toString();
    }
}
