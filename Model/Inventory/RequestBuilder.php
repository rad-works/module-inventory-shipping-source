<?php
declare(strict_types=1);

namespace RadWorks\InventoryShippingSource\Model\Inventory;

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
    /**
     * @var AddressInterfaceFactory
     */
    private AddressInterfaceFactory $addressFactory;

    /**
     * @var StockByWebsiteIdResolverInterface
     */
    private StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver;

    /**
     * @var ItemRequestInterfaceFactory
     */
    private ItemRequestInterfaceFactory $itemRequestFactory;

    /**
     * @var InventoryRequestInterfaceFactory
     */
    private InventoryRequestInterfaceFactory $inventoryRequestFactory;

    /**
     * @var InventoryRequestExtensionInterfaceFactory
     */
    private InventoryRequestExtensionInterfaceFactory $inventoryRequestExtensionFactory;

    /**
     * @param AddressInterfaceFactory $addressFactory
     * @param ItemRequestInterfaceFactory $itemRequestFactory
     * @param InventoryRequestInterfaceFactory $inventoryRequestFactory
     * @param InventoryRequestExtensionInterfaceFactory $inventoryRequestExtensionFactory
     * @param StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver
     */
    public function __construct(
        AddressInterfaceFactory                   $addressFactory,
        ItemRequestInterfaceFactory               $itemRequestFactory,
        InventoryRequestInterfaceFactory          $inventoryRequestFactory,
        InventoryRequestExtensionInterfaceFactory $inventoryRequestExtensionFactory,
        StockByWebsiteIdResolverInterface         $stockByWebsiteIdResolver
    ) {
        $this->inventoryRequestExtensionFactory = $inventoryRequestExtensionFactory;
        $this->inventoryRequestFactory = $inventoryRequestFactory;
        $this->itemRequestFactory = $itemRequestFactory;
        $this->stockByWebsiteIdResolver = $stockByWebsiteIdResolver;
        $this->addressFactory = $addressFactory;
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
     * @return array
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
