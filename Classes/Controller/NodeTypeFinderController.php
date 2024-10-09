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
     * @param string $searchTerm
     *
     * @throws \Neos\Eel\Exception
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws \Neos\Neos\Exception
     */
    public function indexAction(?string $searchTerm = null): void
    {
        $this->view->assign('searchTerm', $searchTerm);
        $this->view->assign('nodeTypes', $this->nodeTypeFinderService->getRelevantNodeTypes());

        if (!empty($searchTerm)) {
            $this->view->assign('occurrences', iterator_to_array($this->search($searchTerm)));
            $this->view->assign('limitExceeded', $this->nodeTypeFinderService->isLimitExceeded());
        }
    }

    private function search(string $searchTerm): Generator
    {
        $occurrences = $this->nodeTypeFinderService->findNodeTypeOccurrences(
            $searchTerm,
            $this->controllerContext
        );

        foreach ($occurrences as $occurrence) {
            yield $occurrence;
        }
    }
}
