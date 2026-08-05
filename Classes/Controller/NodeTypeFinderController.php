<?php
declare(strict_types=1);

namespace Netlogix\NodeTypeFinder\Controller;

use Generator;
use Neos\Flow\Annotations as Flow;
use Neos\Fusion\View\FusionView;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Netlogix\NodeTypeFinder\Service\NodeTypeFinderService;

class NodeTypeFinderController extends AbstractModuleController
{
    /**
     * @var FusionView
     */
    protected $defaultViewObjectName = FusionView::class;

    /**
     * @Flow\Inject
     * @var NodeTypeFinderService
     */
    protected $nodeTypeFinderService;

    protected function initializeView(ViewInterface $view)
    {
        parent::initializeView($view);
        $view->setFusionPathPattern('resource://Netlogix.NodeTypeFinder/Private/Fusion/Backend');
    }

    /**
     * @param string|null $searchTerm
     * @param string|null $siteNodeName
     * @param string|null $dimensions Serialized dimension combination as offered by the dimension select
     *
     * @throws \Neos\Eel\Exception
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws \Neos\Neos\Exception
     */
    public function indexAction(?string $searchTerm = null, ?string $siteNodeName = null, ?string $dimensions = null): void
    {
        $sites = $this->nodeTypeFinderService->getSites();
        $dimensionCombinations = $this->nodeTypeFinderService->getDimensionCombinations();

        // Only the initial view has no filters at all, a submitted form always sends them.
        // "All sites"/"all dimensions" arrive as an empty string, so they are not prefilled
        // with the default again.
        if ($siteNodeName === null) {
            $siteNodeName = $this->nodeTypeFinderService->getCurrentSiteNodeName();
        }
        if ($dimensions === null) {
            $dimensions = $this->nodeTypeFinderService->getDefaultDimensionCombination();
        }
        if ($dimensionCombinations === []) {
            $dimensions = null;
        }

        $this->view->assign('searchTerm', $searchTerm);
        $this->view->assign('nodeTypes', $this->nodeTypeFinderService->getRelevantNodeTypes());
        $this->view->assign('sites', $sites);
        $this->view->assign('siteNodeName', $siteNodeName);
        $this->view->assign('dimensionCombinations', $dimensionCombinations);
        $this->view->assign('dimensions', $dimensions);

        if (!empty($searchTerm)) {
            $this->view->assign(
                'occurrences',
                iterator_to_array($this->search($searchTerm, $siteNodeName, $dimensions))
            );
            $this->view->assign('limitExceeded', $this->nodeTypeFinderService->isLimitExceeded());
        }
    }

    private function search(string $searchTerm, ?string $siteNodeName, ?string $dimensions): Generator
    {
        $occurrences = $this->nodeTypeFinderService->findNodeTypeOccurrences(
            $searchTerm,
            $this->controllerContext,
            $siteNodeName ?: null,
            $this->nodeTypeFinderService->resolveDimensionValues($dimensions)
        );

        foreach ($occurrences as $occurrence) {
            yield $occurrence;
        }
    }
}
