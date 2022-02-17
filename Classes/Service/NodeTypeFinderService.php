<?php
declare(strict_types=1);

namespace Netlogix\NodeTypeFinder\Service;

use Neos\Flow\Annotations as Flow;
use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Neos\Service\LinkingService;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeFinderService
{
    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @param string $nodeTypeName
     * @param Uri $baseUri
     * @return array
     * @throws \Neos\Eel\Exception
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws \Neos\Neos\Exception
     */
    public function findNodeTypeOccurrences(
        string $nodeTypeName,
        ControllerContext $controllerContext,
        bool $invisibleContentShown = false,
        bool $removedContentShown = false,
        bool $inaccessibleContentShown = false
    ): array
    {
        $occurrences = [];

        $contextFactorySettings = [
            'workspaceName' => 'live',
            'invisibleContentShown' => $invisibleContentShown,
            'removedContentShown' => $removedContentShown,
            'inaccessibleContentShown' => $inaccessibleContentShown
        ];
        $context = $this->contextFactory->create($contextFactorySettings);

        $nodes = (new FlowQuery([$context->getCurrentSiteNode()]))
            ->find('/')
            ->find('[instanceof '.$nodeTypeName.']')
            ->get();

        foreach ($nodes as $node) {
            if (!$node instanceof NodeInterface) {
                continue;
            }

            $documentQuery = new FlowQuery([$node]);
            $documentNode = $documentQuery->closest('[instanceof Neos.Neos:Document]')->get(0);

            if (!$documentNode instanceof NodeInterface) {
                continue;
            }

            $uri = $this->linkingService->createNodeUri(
                $controllerContext,
                $documentNode,
                null,
                null,
                true
            );

            if (!array_key_exists($uri, $occurrences)) {
                $occurrences[$uri] = [
                    'url' => str_replace('./', '', $uri),
                    'label' => $documentNode->getLabel()
                ];
            }
        }

        return array_values($occurrences);
    }
}
