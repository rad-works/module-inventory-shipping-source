<?php
declare(strict_types=1);

namespace DmiRud\InventoryShippingSource\Plugin\Magento\Quote\Model\Quote\Address\RateCollectorInterface;

use DmiRud\InventoryShippingSource\Model\Inventory\SourceSelectionProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\Quote\Model\Quote\Address\RateCollectorInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\Error;
use Magento\Shipping\Model\Rate\PackageResult;
use Magento\Shipping\Model\Rate\PackageResultFactory;
use Magento\Shipping\Model\Rate\ResultFactory;

class CollectRatesPerInventorySource
{
    private const XML_PATH_USE_INVENTORY_SOURCE_ORIGIN = 'shipping/rates_collector/use_inventory_source_origin';

    public function __construct(
        private readonly ScopeConfigInterface             $scopeConfig,
        private readonly PackageResultFactory             $packageResultFactory,
        private readonly ResultFactory                    $rateResultFactory,
        private readonly SourceSelectionProviderInterface $inventorySourceSelectionProvider,
        private readonly SourceRepositoryInterface        $inventorySourceRepository
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
        $itemsToShip = [];
        foreach ($this->inventorySourceSelectionProvider->get($request) as $sourceSelectionItem) {
            $sku = $sourceSelectionItem->getSku();
            $qty = $sourceSelectionItem->getQtyToDeduct();
            $selectedSources[$sourceSelectionItem->getSourceCode()][$sku] = $qty;
            $itemsToShip[$sku] = ($itemsToShip[$sku] ?? 0) + $qty;
        }

        $requests = $this->prepareRateRequests($request, $selectedSources);
        $availableInAllResultsMethods = [];
        $itemsRatesCollected = [];
        $allResultRates = [];
        /**
         * Collect rates for each source rate request
         */
        foreach ($requests as $sourceRequest) {
            $proceed($sourceRequest);
            $allResultRates[] = $subject->getResult()->getAllRates();
            foreach ($subject->getResult()->getAllRates() as $rate) {
                if ($rate instanceof Error) {
                    continue;
                }

                /**
                 * Ensure that the final result only includes methods that are available in all request results
                 */
                $isAvailableInAllResults = true;
                $method = $rate->getMethod();
                foreach ($selectedSources[$sourceRequest->getSelectedSourceCode()] as $sku => $qty) {
                    $itemsRatesCollected[$method][$sku] = $qty + ($itemsRatesCollected[$method][$sku] ?? 0);
                    if ($itemsToShip[$sku] !== $itemsRatesCollected[$method][$sku]) {
                        $isAvailableInAllResults = false;
                    }
                }

                if ($isAvailableInAllResults) {
                    $availableInAllResultsMethods[] = $method;
                }
            }
            /**
             * Remove all rates from the result to process next rates of the next request
             */
            $subject->getResult()->reset();
        }

        /**
         * Prepare final rate result
         */
        /** @var PackageResult $result */
        $result = $this->packageResultFactory->create();
        foreach ($allResultRates as $rates) {
            $rateResult = $this->rateResultFactory->create();
            foreach ($rates as $rate) {
                if ($rate instanceof Error || in_array($rate->getMethod(), $availableInAllResultsMethods)) {
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
     * Get rate requests based on inventory sources selections
     *
     * @param RateRequest $request
     * @param array $selectedSources
     * @return array
     * @throws NoSuchEntityException
     */
    public function prepareRateRequests(RateRequest $request, array $selectedSources): array
    {
        $requests = [];
        foreach ($selectedSources as $sourceCode => $items) {
            $inventorySource = $this->inventorySourceRepository->get($sourceCode);
            $sourceRequest = clone $request;
            $sourceRequest->setSelectedSourceCode($sourceCode);
            $sourceRequest->setOrigCountryId($inventorySource->getCountryId());
            $sourceRequest->setOrigPostcode($inventorySource->getPostcode());
            $sourceRequest->setOrigRegionId($inventorySource->getRegionId());
            $sourceRequest->setOrigCity($inventorySource->getCity());
            //Fix: carriers use undocumented RateRequest::getOrigRegionCode method
            $sourceRequest->setOrigRegionCode($inventorySource->getRegionId());
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

        return $requests;
    }
}