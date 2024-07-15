<?php
declare(strict_types=1);

namespace DmiRud\InventoryShippingSource\Model\Inventory;

use Magento\InventorySourceSelectionApi\Api\Data\SourceSelectionItemInterface;
use Magento\InventorySourceSelectionApi\Api\GetDefaultSourceSelectionAlgorithmCodeInterface;
use Magento\InventorySourceSelectionApi\Api\SourceSelectionServiceInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;

/**
 * Provides source selections by rate request
 */
class SourceSelectionProvider implements SourceSelectionProviderInterface
{
    /**
     * @var GetDefaultSourceSelectionAlgorithmCodeInterface
     */
    private GetDefaultSourceSelectionAlgorithmCodeInterface $getDefaultSourceSelectionAlgorithmCode;

    /**
     * @var RequestBuilderInterface
     */
    private RequestBuilderInterface $requestBuilder;

    /**
     * @var SourceSelectionServiceInterface
     */
    private SourceSelectionServiceInterface $sourceSelectionService;

    /**
     * @param GetDefaultSourceSelectionAlgorithmCodeInterface $getDefaultSourceSelectionAlgorithmCode
     * @param RequestBuilderInterface $requestBuilder
     * @param SourceSelectionServiceInterface $sourceSelectionService
     */
    public function __construct(
        GetDefaultSourceSelectionAlgorithmCodeInterface $getDefaultSourceSelectionAlgorithmCode,
        RequestBuilderInterface                         $requestBuilder,
        SourceSelectionServiceInterface                 $sourceSelectionService
    ) {
        $this->getDefaultSourceSelectionAlgorithmCode = $getDefaultSourceSelectionAlgorithmCode;
        $this->requestBuilder = $requestBuilder;
        $this->sourceSelectionService = $sourceSelectionService;
    }

    /**
     * Get source selections by rate request skus and quantities
     *
     * @param RateRequest $rateRequest
     * @param string|null $algorithmCode
     * @return SourceSelectionItemInterface[]
     */
    public function get(RateRequest $rateRequest, string $algorithmCode = null): array
    {
        $sourceSelectionResult = $this->sourceSelectionService->execute(
            $this->requestBuilder->build($rateRequest),
            $algorithmCode ?: $this->getDefaultSourceSelectionAlgorithmCode->execute()
        );

        return $sourceSelectionResult->getSourceSelectionItems();
    }
}
