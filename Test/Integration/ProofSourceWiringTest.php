<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration;

use Hryvinskyi\TestOrder\Api\ProofSourceInterface;
use Hryvinskyi\TestOrder\Api\ProofVerifierInterface;
use Hryvinskyi\TestOrder\Api\SecretProviderInterface;
use Hryvinskyi\TestOrder\Model\DeploymentConfigSecretProvider;
use Hryvinskyi\TestOrder\Model\ProofSource\NullProofSource;
use Hryvinskyi\TestOrder\Model\ProofSource\RequestProofSource;
use Hryvinskyi\TestOrder\Model\TestOrderProofVerifier;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Only a REST or GraphQL request is read for a proof; every other area has none. The proof is checked against the
 * deployment configuration's secret.
 *
 * @magentoAppIsolation enabled
 */
class ProofSourceWiringTest extends TestCase
{
    /**
     * Outside REST and GraphQL there is no proof to read.
     *
     * @magentoAppArea global
     * @return void
     */
    public function testTheGlobalAreaReadsNoProof(): void
    {
        self::assertInstanceOf(NullProofSource::class, $this->proofSource());
    }

    /**
     * A storefront page carries no proof either.
     *
     * @magentoAppArea frontend
     * @return void
     */
    public function testTheStorefrontReadsNoProof(): void
    {
        self::assertInstanceOf(NullProofSource::class, $this->proofSource());
    }

    /**
     * A REST call is read for its proof header.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testARestCallIsReadForItsProof(): void
    {
        self::assertInstanceOf(RequestProofSource::class, $this->proofSource());
    }

    /**
     * A GraphQL call is read for its proof header.
     *
     * @magentoAppArea graphql
     * @return void
     */
    public function testAGraphQlCallIsReadForItsProof(): void
    {
        self::assertInstanceOf(RequestProofSource::class, $this->proofSource());
    }

    /**
     * The verifier checks proofs against the deployment configuration's secret.
     *
     * @magentoAppArea global
     * @return void
     */
    public function testProofsAreCheckedAgainstTheDeploymentConfigurationsSecret(): void
    {
        $objectManager = Bootstrap::getObjectManager();

        self::assertInstanceOf(TestOrderProofVerifier::class, $objectManager->get(ProofVerifierInterface::class));
        self::assertInstanceOf(
            DeploymentConfigSecretProvider::class,
            $objectManager->get(SecretProviderInterface::class)
        );
    }

    /**
     * @return ProofSourceInterface
     */
    private function proofSource(): ProofSourceInterface
    {
        $proofSource = Bootstrap::getObjectManager()->get(ProofSourceInterface::class);
        self::assertInstanceOf(ProofSourceInterface::class, $proofSource);

        return $proofSource;
    }
}
