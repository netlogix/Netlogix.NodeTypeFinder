<?php
declare(strict_types=1);

namespace Netlogix\NodeTypeFinder\Service;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Neos\Service\LinkingService;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Neos\Service\UserService;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeFinderService
{
    /**
     * @var LinkingService
     * @Flow\Inject
     */
    protected $linkingService;

    /**
     * @var ContextFactoryInterface
     * @Flow\Inject
     */
    protected $contextFactory;

    /**
     * @var ContentDimensionCombinator
     * @Flow\Inject
     */
    protected $contentDimensionCombinator;

    /**
     * @var UserService
     * @Flow\Inject
     */
    protected $userService;

    /**
     * @var NodeTypeManager
     * @Flow\Inject
     */
    protected $nodeTypeManager;

    protected $limitExceeded = false;

    /**
     * @param string $nodeTypeName
     * @param ControllerContext $controllerContext
     * @return array
     */
    public function findNodeTypeOccurrences(string $nodeTypeName, ControllerContext $controllerContext): array
    {
        $occurrences = [];

        foreach ($this->findNodeTypeOccurrencesInAllDimensions($nodeTypeName) as $node) {
            if (!$node instanceof NodeInterface) {
                continue;
            }

            $documentNode = $this->findClosestDocumentNode($node);
            if (!$documentNode) {
                continue;
            }
            $visible = $this->isNodeVisible($documentNode);
            $uri = $this->buildNodeUri($documentNode, $controllerContext, $visible);
            if ($uri === null) {
                continue;
            }

            if (!array_key_exists($uri, $occurrences)) {
                $occurrences[$uri] = [
                    'url' => str_replace('./', '', $uri),
                    'label' => $documentNode->getLabel(),
                    'visible' => $visible,
                ];
            }
        }

        return array_values($occurrences);
    }

    public function getRelevantNodeTypes(): array
    {
        $nodeTypes = $this->nodeTypeManager->getNodeTypes(false);
        $nodeTypes = array_filter($nodeTypes, fn (NodeType $nodeType) => $nodeType->isOfType('Neos.Neos:Document') || $nodeType->isOfType('Neos.Neos:Content'));

        return array_map(fn (NodeType $nodeType) => ['name' => $nodeType->getName(), 'label' => $nodeType->getLabel()], $nodeTypes);
    }

    /**
     * @return bool
     */
    public function isLimitExceeded(): bool
    {
        return $this->limitExceeded;
    }

    private function findNodeTypeOccurrencesInAllDimensions(string $nodeTypeName): iterable
    {
        $dimensionCombinations = $this->contentDimensionCombinator->getAllAllowedCombinations();
        $count = 0;
        $maxResults = 100;

        foreach ($dimensionCombinations as $dimensionCombination) {
            if ($count >= $maxResults) {
                $this->limitExceeded = true;
                break;
            }

            foreach ($this->findNodeTypeOccurrencesInDimensions($nodeTypeName, $dimensionCombination, $maxResults - $count) as $node) {
                yield $node;
                $count++;

                if ($count >= $maxResults) {
                    $this->limitExceeded = true;
                    break;
                }
            }
        }
    }

    private function findNodeTypeOccurrencesInDimensions(string $nodeTypeName, array $dimensionValues, int $remainingLimit): iterable
    {
        $context = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => $dimensionValues,
            'invisibleContentShown' => true,
        ]);

        yield from (new FlowQuery([$context->getRootNode()]))
            ->find('[instanceof '.$nodeTypeName.']')
            ->slice($remainingLimit)
            ->get();
    }

    public function findClosestDocumentNode(NodeInterface $node): ?NodeInterface
    {
        $documentQuery = new FlowQuery([$node]);
        $documentNode = $documentQuery->closest('[instanceof Neos.Neos:Document]')->get(0);

        return $documentNode instanceof NodeInterface ? $documentNode : null;
    }

    public function isNodeVisible(NodeInterface $node): bool
    {
        $parent = $node;
        while ($parent !== null) {
            if (!$parent->isVisible()) {
                return false;
            }
            $parent = $parent->getParent();
        }

        return true;
    }

    public function buildNodeUri(NodeInterface $node, ControllerContext $controllerContext, bool $visible): ?string
    {
        if (!$visible) {
            $newProperties = array_merge($node->getContext()->getProperties(), [
                'workspaceName' => $this->userService->getPersonalWorkspaceName() ?? 'live',
            ]);
            $node = $this->contextFactory->create($newProperties)->getNodeByIdentifier($node->getIdentifier());

            if (!$node) {
                return null;
            }
        }

        return $this->linkingService->createNodeUri(
            $controllerContext,
            $node,
            null,
            null,
            true
        );
    }

}
