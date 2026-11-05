<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Domain;

/**
 * What the end-to-end runner proves it may do with one proof. The action is part of what is signed, so a proof made
 * for one action is never accepted for another: a placement proof cannot end an order, and a recognition probe's proof
 * cannot place one.
 *
 * The set is closed: the shop honours a proof on exactly these three surfaces.
 */
enum TestOrderAction: string
{
    /**
     * Place an order from a cart as a test order; the subject is the cart's masked id.
     */
    case Place = 'place';

    /**
     * End the test order placed from a cart; the subject is the cart's masked id.
     */
    case Abandon = 'abandon';

    /**
     * Ask whether the shop recognises the runner at all; the subject is a fixed word.
     */
    case Recognise = 'recognise';
}
