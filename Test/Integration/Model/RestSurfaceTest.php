<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Model;

use Laminas\Http\Header\HeaderInterface;
use Laminas\Http\Headers;
use Hryvinskyi\TestOrder\Api\RecognitionGuardInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestOrderAbandonmentInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRecognitionInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Model\Data\AbandonResult;
use Hryvinskyi\TestOrder\Model\ProofSource\RequestProofSource;
use Hryvinskyi\TestOrder\Model\RecognitionGuardPool;
use Hryvinskyi\TestOrder\Model\TestOrderRecognition;
use Hryvinskyi\TestOrder\Model\UndoRunner;
use Hryvinskyi\TestOrder\Test\Integration\CartRows;
use Hryvinskyi\TestOrder\Test\Integration\Fixture\RecordingUndoStep;
use Hryvinskyi\TestOrder\Test\Integration\OrderRows;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Magento\Framework\Webapi\ServiceOutputProcessor;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Magento\Webapi\Controller\Rest\Router;
use PHPUnit\Framework\TestCase;

/**
 * The two REST routes of the end-to-end runner, through the REST area's own router, services and output: the
 * recognition probe and abandoning a test order, both authorised by the proof header alone.
 *
 * The abandoned order is a bare row that is already complete, so no cancellation runs and no integration is called;
 * the undo runner gets a recording step in place of the real ones.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 * @magentoAppArea webapi_rest
 */
class RestSurfaceTest extends TestCase
{
    private const string SECRET = 'an-integration-test-order-secret-0123456789';

    private const string MASKED_ID = 'TestOrderAbandonment00000000000a';

    private const string OVERRIDE_VARIABLE = 'MAGENTO_DC__OVERRIDE';

    /**
     * @var string|false The override variable as it was before the test
     */
    private string|false $previousOverride = false;

    /**
     * @var RecordingUndoStep
     */
    private RecordingUndoStep $step;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->previousOverride = getenv(self::OVERRIDE_VARIABLE);
        putenv(self::OVERRIDE_VARIABLE . '=' . json_encode(['test_order' => ['proof_secret' => self::SECRET]]));
        $this->deploymentConfig()->resetData();

        $this->step = new RecordingUndoStep();
        $objectManager = $this->objectManager();
        $runner = $objectManager->create(UndoRunner::class, ['steps' => ['recording' => $this->step]]);
        self::assertInstanceOf(UndoRunner::class, $runner);
        $objectManager->addSharedInstance($runner, UndoRunner::class);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        putenv(
            $this->previousOverride === false
                ? self::OVERRIDE_VARIABLE
                : self::OVERRIDE_VARIABLE . '=' . $this->previousOverride
        );
        $this->deploymentConfig()->resetData();
        $this->sendProof(null);
        $this->objectManager()->removeSharedInstance(UndoRunner::class);
    }

    /**
     * Both routes are served by this module's services and open to anonymous callers.
     *
     * @return void
     */
    public function testTheRoutesReachTheServicesAnonymously(): void
    {
        $probe = $this->route('POST', '/V1/test-orders/recognition');
        self::assertSame(
            [TestOrderRecognitionInterface::class, 'confirm', ['anonymous']],
            [$probe->getServiceClass(), $probe->getServiceMethod(), $probe->getAclResources()]
        );

        $request = $this->restRequest('POST', '/V1/test-orders/' . self::MASKED_ID . '/abandon');
        $abandon = $this->router()->match($request);
        self::assertSame(
            [TestOrderAbandonmentInterface::class, 'abandon', ['anonymous']],
            [$abandon->getServiceClass(), $abandon->getServiceMethod(), $abandon->getAclResources()]
        );
        self::assertSame(self::MASKED_ID, $request->getParam('cartId'));
    }

    /**
     * A recognition proof is confirmed, and the answer is the JSON `true` the runner waits for.
     *
     * @return void
     */
    public function testTheProbeConfirmsARecognitionProof(): void
    {
        $this->sendProof($this->sign(TestOrderAction::Recognise, TestOrderRecognitionInterface::SUBJECT));

        $answer = $this->recognition()->confirm();

        self::assertTrue($this->restAnswer($answer, TestOrderRecognitionInterface::class, 'confirm'));
    }

    /**
     * A proof made with another secret, for a cart, or for another action is refused by the probe.
     *
     * @dataProvider refusedProbeProofs
     * @param string $secret Secret signed with
     * @param string $subject Subject signed over
     * @param TestOrderAction $action Action signed for
     * @return void
     */
    public function testTheProbeRefusesAnyOtherProof(string $secret, string $subject, TestOrderAction $action): void
    {
        $this->sendProof($this->sign($action, $subject, $secret));

        $this->expectException(AuthorizationException::class);

        $this->recognition()->confirm();
    }

    /**
     * @return array<string, array{string, string, TestOrderAction}>
     */
    public static function refusedProbeProofs(): array
    {
        return [
            'another secret' => [
                'another-integration-test-order-secret-98765',
                TestOrderRecognitionInterface::SUBJECT,
                TestOrderAction::Recognise,
            ],
            'made for a cart' => [self::SECRET, self::MASKED_ID, TestOrderAction::Recognise],
            'made for placing' => [self::SECRET, TestOrderRecognitionInterface::SUBJECT, TestOrderAction::Place],
        ];
    }

    /**
     * The container's recognition guard is the pool the modules contribute to.
     *
     * @return void
     */
    public function testTheGuardIsThePool(): void
    {
        self::assertInstanceOf(
            RecognitionGuardPool::class,
            $this->objectManager()->get(RecognitionGuardInterface::class)
        );
    }

    /**
     * A guard in the pool that objects forbids a probe whose proof is valid, with the guard's reason.
     *
     * @return void
     */
    public function testAGuardsRefusalForbidsTheProbe(): void
    {
        $guard = new class implements RecognitionGuardInterface {
            /**
             * @inheritDoc
             */
            public function refusalFor(int $storeId): Phrase
            {
                return new Phrase('Test orders are not placed in store %1.', [$storeId]);
            }
        };
        $objectManager = $this->objectManager();
        $objectManager->addSharedInstance(
            new RecognitionGuardPool(['refusing' => $guard]),
            RecognitionGuardPool::class
        );
        $objectManager->removeSharedInstance(TestOrderRecognition::class);
        $this->sendProof($this->sign(TestOrderAction::Recognise, TestOrderRecognitionInterface::SUBJECT));

        try {
            $this->recognition()->confirm();
            self::fail('A probe a guard objects to was confirmed.');
        } catch (WebapiException $refusal) {
            self::assertSame(WebapiException::HTTP_FORBIDDEN, $refusal->getHttpCode());
            self::assertStringStartsWith('Test orders are not placed in store ', $refusal->getMessage());
        } finally {
            $objectManager->removeSharedInstance(RecognitionGuardPool::class);
            $objectManager->removeSharedInstance(TestOrderRecognition::class);
        }
    }

    /**
     * With a proof for the guest cart, its test order is abandoned and answered as `{increment_id, state, deleted}`.
     *
     * @return void
     */
    public function testAbandoningWithAProofForTheCart(): void
    {
        $quoteId = $this->completeTestOrder();
        $this->sendProof($this->sign(TestOrderAction::Abandon, self::MASKED_ID));

        $answer = $this->abandonment()->abandon(self::MASKED_ID);

        self::assertSame(
            ['increment_id' => 'TESTORDER-' . $quoteId, 'state' => 'complete', 'deleted' => false],
            $this->restAnswer($answer, TestOrderAbandonmentInterface::class, 'abandon')
        );
        self::assertSame(['TESTORDER-' . $quoteId], $this->step->undone);
    }

    /**
     * Abandoning without a proof for the cart is refused, and the test order is left as it was.
     *
     * @dataProvider refusedAbandonProofs
     * @param string|null $subject Subject the proof is made over; null to send none
     * @param TestOrderAction $action Action the proof is made for
     * @return void
     */
    public function testAbandoningWithoutAProofForTheCartIsRefused(?string $subject, TestOrderAction $action): void
    {
        $this->completeTestOrder();
        $this->sendProof($subject === null ? null : $this->sign($action, $subject));

        try {
            $this->abandonment()->abandon(self::MASKED_ID);
            self::fail('A test order was abandoned without a proof for its cart.');
        } catch (AuthorizationException) {
            self::assertSame([], $this->step->undone);
        }
    }

    /**
     * @return array<string, array{string|null, TestOrderAction}>
     */
    public static function refusedAbandonProofs(): array
    {
        return [
            'no proof' => [null, TestOrderAction::Abandon],
            'made for another cart' => ['TestOrderAbandonment00000000000b', TestOrderAction::Abandon],
            'made for the probe' => [TestOrderRecognitionInterface::SUBJECT, TestOrderAction::Recognise],
            'made for placing the cart' => [self::MASKED_ID, TestOrderAction::Place],
        ];
    }

    /**
     * An answer without an order number leaves the number out, as the runner expects.
     *
     * @return void
     */
    public function testAnAnswerWithoutANumberLeavesItOut(): void
    {
        self::assertSame(
            ['state' => 'canceled', 'deleted' => true],
            $this->restAnswer(new AbandonResult('canceled', true), TestOrderAbandonmentInterface::class, 'abandon')
        );
    }

    /**
     * A guest cart with its masked id, recognised, from which a complete order exists.
     *
     * @return int Cart (quote) entity id
     */
    private function completeTestOrder(): int
    {
        $quoteId = CartRows::insertClosedGuestCart(self::MASKED_ID);
        $this->records()->recognise($quoteId);
        $orderId = OrderRows::insert($quoteId);
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $resource->getConnection()->update(
            $resource->getTableName('sales_order'),
            ['state' => 'complete', 'status' => 'complete'],
            ['entity_id = ?' => $orderId]
        );

        return $quoteId;
    }

    /**
     * The REST answer a service's result becomes.
     *
     * @param mixed $result What the service returned
     * @param string $service Service interface
     * @param string $method Service method
     * @return mixed
     */
    private function restAnswer(mixed $result, string $service, string $method): mixed
    {
        $processor = Bootstrap::getObjectManager()->get(ServiceOutputProcessor::class);
        self::assertInstanceOf(ServiceOutputProcessor::class, $processor);

        return $processor->process($result, $service, $method);
    }

    /**
     * The route the REST router matches for a request.
     *
     * @param string $method HTTP method
     * @param string $path Path after the store code
     * @return Router\Route
     */
    private function route(string $method, string $path): Router\Route
    {
        return $this->router()->match($this->restRequest($method, $path));
    }

    /**
     * The REST area's router.
     *
     * @return Router
     */
    private function router(): Router
    {
        $router = Bootstrap::getObjectManager()->get(Router::class);
        self::assertInstanceOf(Router::class, $router);

        return $router;
    }

    /**
     * A REST request for a method and path.
     *
     * @param string $method HTTP method
     * @param string $path Path after the store code
     * @return RestRequest
     */
    private function restRequest(string $method, string $path): RestRequest
    {
        $request = Bootstrap::getObjectManager()->create(RestRequest::class);
        self::assertInstanceOf(RestRequest::class, $request);
        $request->setMethod($method);
        $request->setPathInfo($path);

        return $request;
    }

    /**
     * The container's recognition probe.
     *
     * @return TestOrderRecognitionInterface
     */
    private function recognition(): TestOrderRecognitionInterface
    {
        $recognition = Bootstrap::getObjectManager()->get(TestOrderRecognitionInterface::class);
        self::assertInstanceOf(TestOrderRecognitionInterface::class, $recognition);

        return $recognition;
    }

    /**
     * The container's abandonment surface.
     *
     * @return TestOrderAbandonmentInterface
     */
    private function abandonment(): TestOrderAbandonmentInterface
    {
        $abandonment = Bootstrap::getObjectManager()->get(TestOrderAbandonmentInterface::class);
        self::assertInstanceOf(TestOrderAbandonmentInterface::class, $abandonment);

        return $abandonment;
    }

    /**
     * The container's record writer.
     *
     * @return TestCartRecordsInterface
     */
    private function records(): TestCartRecordsInterface
    {
        $records = Bootstrap::getObjectManager()->get(TestCartRecordsInterface::class);
        self::assertInstanceOf(TestCartRecordsInterface::class, $records);

        return $records;
    }

    /**
     * Send the proof header with the request being served, or stop sending it.
     *
     * @param string|null $proof Proof; null to send none
     * @return void
     */
    private function sendProof(?string $proof): void
    {
        $request = Bootstrap::getObjectManager()->get(RequestInterface::class);
        self::assertInstanceOf(\Magento\Framework\App\Request\Http::class, $request);
        $headers = $request->getHeaders();
        self::assertInstanceOf(Headers::class, $headers);
        $sent = $headers->get(RequestProofSource::HEADER);
        if ($sent instanceof HeaderInterface) {
            $headers->removeHeader($sent);
        }

        if ($proof !== null) {
            $headers->addHeaderLine(RequestProofSource::HEADER, $proof);
        }
    }

    /**
     * A fresh proof as the end-to-end runner makes it: an HMAC over `<action>|<subject>|<unix-seconds>`.
     *
     * @param TestOrderAction $action Action signed for
     * @param string $subject Subject signed over
     * @param string $secret Secret signed with
     * @return string
     */
    private function sign(TestOrderAction $action, string $subject, string $secret = self::SECRET): string
    {
        $now = time();

        return $now . '.' . hash_hmac('sha256', $action->value . '|' . $subject . '|' . $now, $secret);
    }

    /**
     * The test framework's object manager.
     *
     * @return ObjectManager
     */
    private function objectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        return $objectManager;
    }

    /**
     * The deployment configuration the secret is read from.
     *
     * @return DeploymentConfig
     */
    private function deploymentConfig(): DeploymentConfig
    {
        $deploymentConfig = Bootstrap::getObjectManager()->get(DeploymentConfig::class);
        self::assertInstanceOf(DeploymentConfig::class, $deploymentConfig);

        return $deploymentConfig;
    }
}
