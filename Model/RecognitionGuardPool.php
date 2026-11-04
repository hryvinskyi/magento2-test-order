<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\RecognitionGuardInterface;
use Magento\Framework\Phrase;

/**
 * Asks every contributed recognition guard, in the pool's order, and passes on the first refusal.
 *
 * With no guard contributed nothing is refused.
 */
class RecognitionGuardPool implements RecognitionGuardInterface
{
    /**
     * @var list<RecognitionGuardInterface>
     */
    private readonly array $guards;

    /**
     * @param array<mixed> $guards Recognition guards by name, in the order they are asked
     * @throws \InvalidArgumentException When an item of the pool is not a recognition guard
     */
    public function __construct(array $guards = [])
    {
        $checked = [];
        foreach ($guards as $name => $guard) {
            if (!$guard instanceof RecognitionGuardInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'The test-order recognition guard "%s" does not implement %s.',
                    $name,
                    RecognitionGuardInterface::class
                ));
            }
            $checked[] = $guard;
        }
        $this->guards = $checked;
    }

    /**
     * @inheritDoc
     */
    public function refusalFor(int $storeId): ?Phrase
    {
        foreach ($this->guards as $guard) {
            $refusal = $guard->refusalFor($storeId);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }
}
