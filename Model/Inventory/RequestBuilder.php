<?php
declare(strict_types=1);

namespace DmiRud\InventoryShippingSource\Model\Inventory;

use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterfaceFactory;
use Magento\InventorySourceSelectionApi\Api\Data\InventoryRequestExtensionInterfaceFactory;
use Magento\InventorySourceSelectionApi\Api\Data\InventoryRequestInterface;
use Magento\InventorySourceSelectionApi\Api\Data\InventoryRequestInterfaceFactory;
use Magento\InventorySourceSelectionApi\Api\Data\ItemRequestInterfaceFactory;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;

/**
 * Builds inventory request from rate request
 */
class RequestBuilder implements RequestBuilderInterface
{
    public function __construct(
        private readonly AddressInterfaceFactory                   $addressFactory,
        private readonly ItemRequestInterfaceFactory               $itemRequestFactory,
        private readonly InventoryRequestInterfaceFactory          $inventoryRequestFactory,
        private readonly InventoryRequestExtensionInterfaceFactory $inventoryRequestExtensionFactory,
        private readonly StockByWebsiteIdResolverInterface         $stockByWebsiteIdResolver
    )
    {
    }

    /**
     * Build source inventory request from the rate request
     *
     * @param RateRequest $request
     * @return InventoryRequestInterface
     */
    public function build(RateRequest $request): InventoryRequestInterface
    {
        $items = [];
        /** @var CartItemInterface $item */
        foreach ($this->getItems($request) as $shippableItem) {
            $items[] = $this->itemRequestFactory->create($shippableItem);
        }

        $inventoryRequest = $this->inventoryRequestFactory->create([
            'stockId' => $this->stockByWebsiteIdResolver->execute((int)$request->getWebsiteId())->getStockId(),
            'items' => $items
        ]);

        $extensionAttributes = $this->inventoryRequestExtensionFactory->create();
        $extensionAttributes->setDestinationAddress($this->addressFactory->create([
            'country' => $request->getDestCountryId(),
            'postcode' => $request->getDestPostcode(),
            'street' => $request->getDestStreet() ?: '',
            'region' => $request->getDestRegionId() ?: '',
            'city' => $request->getDestCity() ?: ''
        ]));

        $inventoryRequest->setExtensionAttributes($extensionAttributes);

        return $inventoryRequest;
    }

    /**
     * Get items that can be shipped
     *
     * @param RateRequest $request
     * @return CartItemInterface[]
     */
    private function getItems(RateRequest $request): array
    {
        $items = [];
        foreach ($request->getAllItems() as $cartItem) {
            if ($cartItem->getParentItemId() || $cartItem->getProduct()->isVirtual()) {
                continue;
            }

            $items[] = ['sku' => $cartItem->getSku(), 'qty' => $cartItem->getQty()];
        }

        return $items;
    }
}