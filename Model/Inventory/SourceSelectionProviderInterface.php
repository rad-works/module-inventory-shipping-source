<?php
declare(strict_types=1);

namespace DmiRud\InventoryShippingSource\Model\Inventory;

use Magento\InventorySourceSelectionApi\Api\Data\SourceSelectionItemInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;

/**
 * Provides source selections by rate request
 */
interface SourceSelectionProviderInterface
{
    /**
     * Get source selections by rate request skus and quantities
     *
     * @param RateRequest $rateRequest
     * @param string|null $algorithmCode
     * @return SourceSelectionItemInterface[]
     */
    public function get(RateRequest $rateRequest, string $algorithmCode = null): array;
}
