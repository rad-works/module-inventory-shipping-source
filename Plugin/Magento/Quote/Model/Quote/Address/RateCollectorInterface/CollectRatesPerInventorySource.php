<?php
declare(strict_types=1);

namespace RadWorks\InventoryShippingSource\Plugin\Magento\Quote\Model\Quote\Address\RateCollectorInterface;

use RadWorks\InventoryShippingSource\Model\Inventory\SourceSelectionProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\Quote\Model\Quote\Address\RateCollectorInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\AbstractResult;
use Magento\Quote\Model\Quote\Address\RateResult\Error;
use Magento\Shipping\Model\CarrierFactoryInterface;
use Magento\Shipping\Model\Rate\PackageResultFactory;
use Magento\Shipping\Model\Rate\ResultFactory;

/**
 * @TODO coding standards
 * @TODO unit/functional/integration tests
 * @TODO new configuration: calculation algorithm, error notification, replace origin for single or any item, etc.
 * @TODO rename module/repository
 * @TODO test with other UPS services
 * @TODO check what source mode is used
 */
class CollectRatesPerInventorySource
{
    private const XML_PATH_USE_INVENTORY_SOURCE_ORIGIN = 'shipping/rates_collector/use_inventory_source_origin';

    /**
     * @var CarrierFactoryInterface
     */
    private CarrierFactoryInterface $carrierFactory;

    /**
     * @var PackageResultFactory
     */
    private PackageResultFactory $packageResultFactory;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var ResultFactory
     */
    private ResultFactory $rateResultFactory;

    /**
     * @var SourceSelectionProviderInterface
     */
    private SourceSelectionProviderInterface $inventorySourceSelectionProvider;
    /**
     * @var SourceRepositoryInterface
     */
    private SourceRepositoryInterface $inventorySourceRepository;

    /**
     * @param CarrierFactoryInterface $carrierFactory
     * @param PackageResultFactory $packageResultFactory
     * @param ResultFactory $rateResultFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param SourceSelectionProviderInterface $inventorySourceSelectionProvider
     * @param SourceRepositoryInterface $inventorySourceRepository
     */
    public function __construct(
        CarrierFactoryInterface          $carrierFactory,
        PackageResultFactory             $packageResultFactory,
        ResultFactory                    $rateResultFactory,
        ScopeConfigInterface             $scopeConfig,
        SourceSelectionProviderInterface $inventorySourceSelectionProvider,
        SourceRepositoryInterface        $inventorySourceRepository
    )
    {
        $this->carrierFactory = $carrierFactory;
        $this->inventorySourceRepository = $inventorySourceRepository;
        $this->inventorySourceSelectionProvider = $inventorySourceSelectionProvider;
        $this->rateResultFactory = $rateResultFactory;
        $this->scopeConfig = $scopeConfig;
        $this->packageResultFactory = $packageResultFactory;
    }

    /**
     * Collects rates based on request
     *
     * @param RateCollectorInterface $subject
     * @param \Closure $proceed
     * @param RateRequest $request
     * @return RateCollectorInterface
     * @throws NoSuchEntityException
     */
    public function aroundCollectRates(
        RateCollectorInterface $subject,
        \Closure               $proceed,
        RateRequest            $request
    ): RateCollectorInterface
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
        $result = $this->packageResultFactory->create();
        foreach ($allResultRates as $rates) {
            $rateResult = $this->rateResultFactory->create();
            foreach ($rates as $rate) {
                if ($this->isAppendFailedRate($rate) || in_array($rate->getMethod(), $availableInAllResultsMethods)) {
                    $rateResult->append($rate);
                }
            }

            $result->appendPackageResult($rateResult, 1);
        }

        $subject->getResult()->appendResult($result, true);

        return $subject;
    }

    /**
     * Is origin request replacement active
     *
     * @return bool
     */
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

    /**
     * Check if failed rate should be appended to the result
     *
     * @param mixed $rate
     * @return bool
     */
    public function isAppendFailedRate(AbstractResult $rate): bool
    {
        if (!$rate instanceof Error) {
            return false;
        }

        $carrier = $this->carrierFactory->getIfActive($rate->getCarrier());
        if ($carrier || $carrier->getConfigData('showmethod') == 0) {
            return false;
        }

        return true;
    }
}
