<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Fixture;

use Hryvinskyi\TestOrder\Api\Data\EventOrdersInterface;
use Hryvinskyi\TestOrder\Api\Data\EventOrdersInterfaceFactory;
use Hryvinskyi\TestOrder\Api\OrderEventReaderInterface;
use Hryvinskyi\TestOrder\Model\Data\EventOrders;
use Hryvinskyi\TestOrder\Model\EventReader\CreditmemoReader;
use Hryvinskyi\TestOrder\Model\EventReader\InvoiceReader;
use Hryvinskyi\TestOrder\Model\EventReader\OrderAddressReader;
use Hryvinskyi\TestOrder\Model\EventReader\OrderIdsReader;
use Hryvinskyi\TestOrder\Model\EventReader\OrderItemReader;
use Hryvinskyi\TestOrder\Model\EventReader\OrderReader;
use Hryvinskyi\TestOrder\Model\EventReader\PaymentReader;
use Hryvinskyi\TestOrder\Model\EventReader\QuoteReader;
use Hryvinskyi\TestOrder\Model\EventReader\ShipmentReader;
use Hryvinskyi\TestOrder\Model\EventReader\StatusHistoryReader;

/**
 * The module's event readers, built the way its `di.xml` pool builds them, around a factory that creates real
 * found-orders values. Used by test cases only.
 */
trait BuildsEventReaders
{
    /**
     * Every reader of the pool, by its pool key.
     *
     * @return array<string, OrderEventReaderInterface>
     */
    private function eventReaders(): array
    {
        $factory = $this->eventOrdersFactory();

        return [
            'order' => new OrderReader($factory),
            'order_ids' => new OrderIdsReader($factory),
            'item' => new OrderItemReader($factory),
            'payment' => new PaymentReader($factory),
            'invoice' => new InvoiceReader($factory),
            'creditmemo' => new CreditmemoReader($factory),
            'shipment' => new ShipmentReader($factory),
            'address' => new OrderAddressReader($factory),
            'address_data_object' => new OrderAddressReader($factory, 'data_object'),
            'status_history' => new StatusHistoryReader($factory),
            'quote' => new QuoteReader($factory),
        ];
    }

    /**
     * A factory that creates real found-orders values from the arguments it is given.
     *
     * @return EventOrdersInterfaceFactory
     */
    private function eventOrdersFactory(): EventOrdersInterfaceFactory
    {
        $factory = $this->getMockBuilder(EventOrdersInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(
            static function (array $data = []): EventOrdersInterface {
                $orders = $data['orders'] ?? [];
                $orderIds = $data['orderIds'] ?? [];
                $quoteIds = $data['quoteIds'] ?? [];

                return new EventOrders(
                    is_array($orders) ? $orders : [],
                    is_array($orderIds) ? $orderIds : [],
                    is_array($quoteIds) ? $quoteIds : []
                );
            }
        );

        return $factory;
    }
}
