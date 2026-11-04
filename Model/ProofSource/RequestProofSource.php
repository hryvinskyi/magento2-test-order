<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model\ProofSource;

use Hryvinskyi\TestOrder\Api\ProofSourceInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\PhpEnvironment\Request as HttpRequest;

/**
 * Reads the proof from the `X-Test-Order-Proof` header of the REST or GraphQL request being served.
 *
 * The request drops an empty value when it builds its headers, so a header that was sent empty is found only among the
 * server parameters. It still counts as sent: a runner bug must end in a refusal, never in an ordinary order.
 */
class RequestProofSource implements ProofSourceInterface
{
    public const string HEADER = 'X-Test-Order-Proof';

    private const string SERVER_PARAMETER = 'HTTP_X_TEST_ORDER_PROOF';

    /**
     * @param RequestInterface $request The request of the area being served
     */
    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    /**
     * @inheritDoc
     */
    public function current(): ?string
    {
        if (!$this->request instanceof HttpRequest) {
            return null;
        }

        $value = $this->request->getHeader(self::HEADER);
        if (is_string($value)) {
            return $value;
        }

        $sent = $this->request->getServer(self::SERVER_PARAMETER);

        return is_string($sent) ? $sent : null;
    }
}
