<?php
declare(strict_types=1);

namespace Netlogix\NodeTypeFinder\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Neos\Neos\FrontendRouting\NodeUriBuilder;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\FrontendRouting\Options;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeFinderService
{
    /**
     * @var ContentRepositoryRegistry
     * @Flow\Inject
     */
    protected $contentRepositoryRegistry;

    /**
     * @var NodeUriBuilderFactory
     * @Flow\Inject
     */
    protected $nodeUriBuilderFactory;

    /**
     * @var NodeLabelGeneratorInterface
     * @Flow\Inject
     */
    protected $nodeLabelGenerator;

    /**
     * @var SiteRepository
     * @Flow\Inject
     */
    protected $siteRepository;

    /**
     * @var DomainRepository
     * @Flow\Inject
     */
    protected $domainRepository;

    /**
     * @var int
     * @Flow\InjectConfiguration(path="maxResults")
     */
    protected int $maxResults = 100;

    protected $limitExceeded = false;

    /**
     * @param string $nodeTypeName
     * @param ActionRequest $actionRequest
     * @param string|null $siteNodeName Restrict the search to a single site, null searches all sites
     * @param DimensionSpacePoint|null $dimensionSpacePoint Restrict the search to a single dimension space point, null searches all
     * @return array
     */
    public function findNodeTypeOccurrences(
        string $nodeTypeName,
        ActionRequest $actionRequest,
        ?string $siteNodeName = null,
        ?DimensionSpacePoint $dimensionSpacePoint = null
    ): array {
        $this->limitExceeded = false;
        $nodeUriBuilder = $this->nodeUriBuilderFactory->forActionRequest($actionRequest);
        $occurrences = [];

        foreach ($this->findNodeTypeOccurrencesInSites($nodeTypeName, $siteNodeName, $dimensionSpacePoint) as [$node, $subgraph]) {
            $documentNode = $this->findClosestDocumentNode($node, $subgraph);
            if (!$documentNode) {
                continue;
            }
            $visible = $this->isNodeVisible($documentNode);
            $uri = $this->buildNodeUri($documentNode, $nodeUriBuilder, $visible);
            if ($uri === null) {
                continue;
            }

            if (!array_key_exists($uri, $occurrences)) {
                $occurrences[$uri] = [
                    'url' => str_replace('./', '', $uri),
                    'label' => $this->nodeLabelGenerator->getLabel($documentNode),
                    'visible' => $visible,
                ];
            }
        }

        return array_values($occurrences);
    }

    public function getRelevantNodeTypes(): array
    {
        $nodeTypes = [];
        foreach ($this->getContentRepositories() as $contentRepository) {
            foreach ($contentRepository->getNodeTypeManager()->getNodeTypes(false) as $nodeType) {
                if ($nodeType->isOfType(NodeTypeNameFactory::NAME_DOCUMENT) || $nodeType->isOfType(NodeTypeNameFactory::NAME_CONTENT)) {
                    $nodeTypes[$nodeType->name->value] = $nodeType;
                }
            }
        }

        return array_map(fn (NodeType $nodeType) => ['name' => $nodeType->name->value, 'label' => $nodeType->getLabel()], array_values($nodeTypes));
    }

    /**
     * All sites that can be searched.
     *
     * @return array<int, array{nodeName: string, label: string}>
     */
    public function getSites(): array
    {
        $sites = array_map(
            fn (Site $site) => ['nodeName' => $site->getNodeName()->value, 'label' => $site->getName()],
            $this->siteRepository->findAll()->toArray()
        );

        usort($sites, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return $sites;
    }

    /**
     * Node name of the site belonging to the currently requested domain, falling back to the default site.
     */
    public function getCurrentSiteNodeName(): ?string
    {
        return $this->getCurrentSite()?->getNodeName()->value;
    }

    /**
     * All allowed dimension space points, empty if no content dimensions are configured.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getDimensionCombinations(): array
    {
        $combinations = [];
        foreach ($this->getContentRepositories() as $contentRepository) {
            foreach ($contentRepository->getVariationGraph()->getDimensionSpacePoints() as $dimensionSpacePoint) {
                if ($dimensionSpacePoint->coordinates === []) {
                    continue;
                }
                $combinations[$dimensionSpacePoint->hash] = [
                    'value' => $dimensionSpacePoint->toJson(),
                    'label' => $this->buildDimensionCombinationLabel($contentRepository, $dimensionSpacePoint),
                ];
            }
        }

        return array_values($combinations);
    }

    /**
     * The serialized default dimension space point of the current site, null if no content dimensions are configured.
     */
    public function getDefaultDimensionCombination(): ?string
    {
        $combinations = $this->getDimensionCombinations();
        if ($combinations === []) {
            return null;
        }

        $serialized = $this->getCurrentSite()?->getConfiguration()->defaultDimensionSpacePoint->toJson();

        return $this->resolveDimensionValues($serialized) !== null ? $serialized : $combinations[0]['value'];
    }

    /**
     * Convert a serialized dimension space point back into a dimension space point.
     *
     * Only dimension space points that are allowed by a content repository are accepted,
     * everything else resolves to null which means "search all dimension space points".
     */
    public function resolveDimensionValues(?string $serializedDimensionValues): ?DimensionSpacePoint
    {
        if ($serializedDimensionValues === null || $serializedDimensionValues === '') {
            return null;
        }

        foreach ($this->getContentRepositories() as $contentRepository) {
            foreach ($contentRepository->getVariationGraph()->getDimensionSpacePoints() as $dimensionSpacePoint) {
                if ($dimensionSpacePoint->coordinates !== [] && $dimensionSpacePoint->toJson() === $serializedDimensionValues) {
                    return $dimensionSpacePoint;
                }
            }
        }

        return null;
    }

    /**
     * @return bool
     */
    public function isLimitExceeded(): bool
    {
        return $this->limitExceeded;
    }

    /**
     * @return iterable<int, array{Node, ContentSubgraphInterface}>
     */
    private function findNodeTypeOccurrencesInSites(
        string $nodeTypeName,
        ?string $siteNodeName,
        ?DimensionSpacePoint $dimensionSpacePoint
    ): iterable {
        $count = 0;

        foreach ($this->siteRepository->findAll() as $site) {
            if ($siteNodeName !== null && $site->getNodeName()->value !== $siteNodeName) {
                continue;
            }

            $contentRepository = $this->contentRepositoryRegistry->get($site->getConfiguration()->contentRepositoryId);
            $dimensionSpacePoints = $dimensionSpacePoint !== null
                ? [$dimensionSpacePoint]
                : $contentRepository->getVariationGraph()->getDimensionSpacePoints();

            foreach ($dimensionSpacePoints as $currentDimensionSpacePoint) {
                if ($count >= $this->maxResults) {
                    $this->limitExceeded = true;
                    return;
                }

                $subgraph = $contentRepository->getContentGraph(WorkspaceName::forLive())
                    ->getSubgraph($currentDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
                $siteNode = $this->findSiteNode($site, $subgraph);
                if (!$siteNode) {
                    continue;
                }

                $nodes = $subgraph->findDescendantNodes(
                    $siteNode->aggregateId,
                    FindDescendantNodesFilter::create(
                        nodeTypes: $nodeTypeName,
                        pagination: ['limit' => $this->maxResults - $count, 'offset' => 0]
                    )
                );

                foreach ($nodes as $node) {
                    yield [$node, $subgraph];
                    $count++;

                    if ($count >= $this->maxResults) {
                        $this->limitExceeded = true;
                        return;
                    }
                }
            }
        }
    }

    private function findSiteNode(Site $site, ContentSubgraphInterface $subgraph): ?Node
    {
        $sitesNode = $subgraph->findRootNodeByType(NodeTypeNameFactory::forSites());

        return $sitesNode ? $subgraph->findNodeByPath($site->getNodeName()->toNodeName(), $sitesNode->aggregateId) : null;
    }

    private function getCurrentSite(): ?Site
    {
        $domain = $this->domainRepository->findOneByActiveRequest();

        return $domain !== null ? $domain->getSite() : $this->siteRepository->findDefault();
    }

    /**
     * @return array<string, ContentRepository>
     */
    private function getContentRepositories(): array
    {
        $contentRepositories = [];
        foreach ($this->siteRepository->findAll() as $site) {
            $contentRepositoryId = $site->getConfiguration()->contentRepositoryId;
            $contentRepositories[$contentRepositoryId->value] ??= $this->contentRepositoryRegistry->get($contentRepositoryId);
        }

        return $contentRepositories;
    }

    private function buildDimensionCombinationLabel(ContentRepository $contentRepository, DimensionSpacePoint $dimensionSpacePoint): string
    {
        $contentDimensionSource = $contentRepository->getContentDimensionSource();

        $labels = [];
        foreach ($dimensionSpacePoint->coordinates as $dimensionName => $value) {
            $dimensionValue = $contentDimensionSource->getDimension(new ContentDimensionId($dimensionName))?->getValue($value);
            $labels[] = $dimensionValue?->getConfigurationValue('label') ?? $value;
        }

        return implode(', ', $labels);
    }

    public function findClosestDocumentNode(Node $node, ContentSubgraphInterface $subgraph): ?Node
    {
        return $subgraph->findClosestNode(
            $node->aggregateId,
            FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_DOCUMENT)
        );
    }

    public function isNodeVisible(Node $node): bool
    {
        return !$node->tags->contain(NeosSubtreeTag::disabled());
    }

    public function buildNodeUri(Node $node, NodeUriBuilder $nodeUriBuilder, bool $visible): ?string
    {
        $nodeAddress = NodeAddress::fromNode($node);

        if (!$visible) {
            return (string)$nodeUriBuilder->previewUriFor($nodeAddress);
        }

        try {
            return (string)$nodeUriBuilder->uriFor($nodeAddress, Options::createForceAbsolute());
        } catch (NoMatchingRouteException) {
            return null;
        }
    }

}
