<?php
declare(strict_types=1);

namespace RadWorks\InventoryShippingSource\Model\Inventory;

use Magento\InventorySourceSelectionApi\Api\Data\InventoryRequestInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;

/**
 * Builds inventory request from rate request
 */
interface RequestBuilderInterface
{
    /**
     * Build source inventory request from the rate request
     *
     * @param RateRequest $request
     * @return InventoryRequestInterface
     */
    public function build(RateRequest $request): InventoryRequestInterface;
}
