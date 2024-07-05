<?php
declare(strict_types=1);

namespace DmiRud\InventoryShippingSource\Plugin\Magento\Quote\Model\Quote\Address\RateCollectorInterface;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterfaceFactory;
use Magento\InventorySourceSelectionApi\Api\Data\InventoryRequestExtensionInterfaceFactory;
use Magento\InventorySourceSelectionApi\Api\Data\InventoryRequestInterface;
use Magento\InventorySourceSelectionApi\Api\Data\InventoryRequestInterfaceFactory;
use Magento\InventorySourceSelectionApi\Api\Data\ItemRequestInterfaceFactory;
use Magento\InventorySourceSelectionApi\Api\GetDefaultSourceSelectionAlgorithmCodeInterface;
use Magento\InventorySourceSelectionApi\Api\SourceSelectionServiceInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Model\Quote\Address\RateCollectorInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\Error;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Shipping\Model\Rate\PackageResult;
use Magento\Shipping\Model\Rate\PackageResultFactory;
use Magento\Shipping\Model\Rate\ResultFactory;

class CollectRatesPerInventorySource
{
    private const XML_PATH_USE_INVENTORY_SOURCE_ORIGIN = 'shipping/rates_collector/use_inventory_source_origin';

    public function __construct(
        private readonly AddressInterfaceFactory                         $addressFactory,
        private readonly ScopeConfigInterface                            $scopeConfig,
        private readonly GetDefaultSourceSelectionAlgorithmCodeInterface $getDefaultSourceSelectionAlgorithmCode,
        private readonly PackageResultFactory                            $packageResultFactory,
        private readonly ResultFactory                                   $rateResultFactory,
        private readonly ItemRequestInterfaceFactory                     $itemRequestFactory,
        private readonly InventoryRequestInterfaceFactory                $inventoryRequestFactory,
        private readonly InventoryRequestExtensionInterfaceFactory       $inventoryRequestExtensionFactory,
        private readonly StockByWebsiteIdResolverInterface               $stockByWebsiteIdResolver,
        private readonly SourceSelectionServiceInterface                 $sourceSelectionService,
        private readonly SourceRepositoryInterface                       $sourceRepository
    )
    {
    }

    /**
     * @param RateCollectorInterface $subject
     * @param \Closure $proceed
     * @param RateRequest $request
     * @return RateCollectorInterface
     * @throws NoSuchEntityException
     */
    public function aroundCollectRates(RateCollectorInterface $subject, \Closure $proceed, RateRequest $request): RateCollectorInterface
    {
        if (!$this->isUseInventorySourceOrigin()) {
            return $proceed($request);
        }

        $selectedSources = [];
        $sourceSelectionResult = $this->sourceSelectionService->execute(
            $this->getInventoryRequest($request),
            $this->getDefaultSourceSelectionAlgorithmCode->execute()
        );

        foreach ($sourceSelectionResult->getSourceSelectionItems() as $sourceSelectionItem) {
            $selectedSources[$sourceSelectionItem->getSourceCode()][$sourceSelectionItem->getSku()] = $sourceSelectionItem->getQtyToDeduct();
        }

        $requests = [];
        foreach ($selectedSources as $sourceCode => $items) {
            $source = $this->sourceRepository->get($sourceCode);
            $sourceRequest = clone $request;
            $sourceRequest->setSelectedSourceCode($sourceCode);
            $sourceRequest->setOrigCountryId($source->getCountryId());
            $sourceRequest->setOrigPostcode($source->getPostcode());
            $sourceRequest->setOrigRegionId($source->getRegionId());
            $sourceRequest->setOrigCity($source->getCity());
            //Fix: some carriers use RateRequest::getOrigRegionCode method
            if ($sourceRequest instanceof DataObject) {
                $sourceRequest->setOrigRegionCode($source->getRegionId());
            }
            $sourceCartItems = [];
            foreach ($request->getAllItems() as $cartItem) {
                if (!array_key_exists($cartItem->getSku(), $items)) {
                    continue;
                }

                $sourceCartItem = clone $cartItem;
                $sourceCartItems[] = $sourceCartItem->setQty($items[$cartItem->getSku()]);
            }

            $sourceRequest->setAllItems($sourceCartItems);
            $requests[] = $sourceRequest;
        }

        $complete = [];
        $methodList = [];
        $packages = [];
        $shippableItems = array_column($this->getRequestShippableItems($request), 'qty', 'sku');
        foreach ($requests as $sourceRequest) {
            //Collect rates per each source request
            $proceed($sourceRequest);
            $requestResult = $subject->getResult();
            $packages[] = $requestResult->getAllRates();
            foreach ($requestResult->getAllRates() as $requestRate) {
                if ($requestRate instanceof Error) {
                    continue;
                }
                /** @var Method $requestRate */
                $isCompleteMethod = true;
                foreach ($selectedSources[$sourceRequest->getSelectedSourceCode()] as $sku => $qty) {
                    $oldQty = $methodList[$requestRate->getMethod()][$sku] ?? 0;
                    $methodList[$requestRate->getMethod()][$sku] = $qty + $oldQty;
                    if ($shippableItems[$sku] != $methodList[$requestRate->getMethod()][$sku]) {
                        $isCompleteMethod = false;
                    }
                }

                if ($isCompleteMethod) {
                    $complete[] = $requestRate->getMethod();
                }
            }

            $subject->getResult()->reset();
        }
        $subject->getResult()->reset();
        /** @var PackageResult $result */
        $result = $this->packageResultFactory->create();
        foreach ($packages as $rates) {
            $rateResult = $this->rateResultFactory->create();
            foreach ($rates as $rate) {
                if ($requestRate instanceof Error || in_array($requestRate->getMethod(), $complete)) {
                    $rateResult->append($rate);
                }

            }

            $result->appendPackageResult($rateResult, 1);

        }
        $subject->getResult()->appendResult($result, true);

        return $subject;
    }

    //@TODO check what source mode is used
    private function isUseInventorySourceOrigin(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_USE_INVENTORY_SOURCE_ORIGIN);
    }

    /**
     * @param RateRequest $request
     * @return InventoryRequestInterface
     */
    private function getInventoryRequest(RateRequest $request): InventoryRequestInterface
    {
        $requestItems = [];
        /** @var CartItemInterface $item */
        foreach ($this->getRequestShippableItems($request) as $shippableItem) {
            $requestItems[] = $this->itemRequestFactory->create($shippableItem);
        }

        $stock = $this->stockByWebsiteIdResolver->execute((int)$request->getWebsiteId());
        $inventoryRequest = $this->inventoryRequestFactory->create([
            'stockId' => $stock->getStockId(),
            'items' => $requestItems
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

    private function getRequestShippableItems(RateRequest $request): array
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