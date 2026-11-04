<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model\EventReader;

use Hryvinskyi\TestOrder\Api\Data\EventOrdersInterface;
use Hryvinskyi\TestOrder\Api\Data\EventOrdersInterfaceFactory;
use Hryvinskyi\TestOrder\Api\OrderEventReaderInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Framework\Event;

/**
 * The order a credit memo (`creditmemo`) refunds, by the order id the credit memo names.
 */
class CreditmemoReader implements OrderEventReaderInterface
{
    private const string DATA_KEY = 'creditmemo';

    /**
     * @param EventOrdersInterfaceFactory $eventOrdersFactory Creates what the reader found
     */
    public function __construct(
        private readonly EventOrdersInterfaceFactory $eventOrdersFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function read(Event $event): EventOrdersInterface
    {
        $carried = $event->getData(self::DATA_KEY);
        $orderId = $carried instanceof CreditmemoInterface ? (int)$carried->getOrderId() : 0;

        return $this->eventOrdersFactory->create(['orderIds' => $orderId > 0 ? [$orderId] : []]);
    }
}
