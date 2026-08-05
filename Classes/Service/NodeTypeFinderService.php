<?php
declare(strict_types=1);

namespace Netlogix\NodeTypeFinder\Service;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
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
     * @var ContentDimensionPresetSourceInterface
     * @Flow\Inject
     */
    protected $contentDimensionPresetSource;

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
     * @var UserService
     * @Flow\Inject
     */
    protected $userService;

    /**
     * @var NodeTypeManager
     * @Flow\Inject
     */
    protected $nodeTypeManager;

    /**
     * @var int
     * @Flow\InjectConfiguration(path="maxResults")
     */
    protected int $maxResults = 100;

    protected $limitExceeded = false;

    /**
     * @param string $nodeTypeName
     * @param ControllerContext $controllerContext
     * @param string|null $siteNodeName Restrict the search to a single site, null searches all sites
     * @param array|null $dimensionValues Restrict the search to a single dimension combination, null searches all
     * @return array
     */
    public function findNodeTypeOccurrences(
        string $nodeTypeName,
        ControllerContext $controllerContext,
        ?string $siteNodeName = null,
        ?array $dimensionValues = null
    ): array {
        $occurrences = [];
        $site = $siteNodeName !== null ? $this->siteRepository->findOneByNodeName($siteNodeName) : null;

        foreach ($this->findNodeTypeOccurrencesInDimensionCombinations($nodeTypeName, $site, $dimensionValues) as $node) {
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
     * All sites that can be searched.
     *
     * @return array<int, array{nodeName: string, label: string}>
     */
    public function getSites(): array
    {
        $sites = array_map(
            fn (Site $site) => ['nodeName' => $site->getNodeName(), 'label' => $site->getName()],
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
        $domain = $this->domainRepository->findOneByActiveRequest();
        $site = $domain !== null ? $domain->getSite() : $this->siteRepository->findDefault();

        return $site !== null ? $site->getNodeName() : null;
    }

    /**
     * All allowed dimension combinations, empty if no content dimensions are configured.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getDimensionCombinations(): array
    {
        if ($this->contentDimensionPresetSource->getAllPresets() === []) {
            return [];
        }

        $combinations = [];
        foreach ($this->contentDimensionCombinator->getAllAllowedCombinations() as $dimensionValues) {
            if ($dimensionValues === []) {
                continue;
            }
            $combinations[] = [
                'value' => self::serializeDimensionValues($dimensionValues),
                'label' => $this->buildDimensionCombinationLabel($dimensionValues),
            ];
        }

        return $combinations;
    }

    /**
     * The serialized default dimension combination, null if no content dimensions are configured.
     */
    public function getDefaultDimensionCombination(): ?string
    {
        $combinations = $this->getDimensionCombinations();
        if ($combinations === []) {
            return null;
        }

        $dimensionValues = [];
        foreach (array_keys($this->contentDimensionPresetSource->getAllPresets()) as $dimensionName) {
            $defaultPreset = $this->contentDimensionPresetSource->getDefaultPreset($dimensionName);
            if ($defaultPreset === null || !isset($defaultPreset['values'])) {
                return $combinations[0]['value'];
            }
            $dimensionValues[$dimensionName] = $defaultPreset['values'];
        }

        $serialized = self::serializeDimensionValues($dimensionValues);

        return $this->resolveDimensionValues($serialized) !== null ? $serialized : $combinations[0]['value'];
    }

    /**
     * Convert a serialized dimension combination back into dimension values.
     *
     * Only combinations that are allowed by the configured presets are accepted,
     * everything else resolves to null which means "search all combinations".
     */
    public function resolveDimensionValues(?string $serializedDimensionValues): ?array
    {
        if ($serializedDimensionValues === null || $serializedDimensionValues === '') {
            return null;
        }

        foreach ($this->contentDimensionCombinator->getAllAllowedCombinations() as $dimensionValues) {
            if ($dimensionValues !== [] && self::serializeDimensionValues($dimensionValues) === $serializedDimensionValues) {
                return $dimensionValues;
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

    private function findNodeTypeOccurrencesInDimensionCombinations(
        string $nodeTypeName,
        ?Site $site,
        ?array $dimensionValues
    ): iterable {
        $dimensionCombinations = $dimensionValues !== null
            ? [$dimensionValues]
            : $this->contentDimensionCombinator->getAllAllowedCombinations();
        $count = 0;

        foreach ($dimensionCombinations as $dimensionCombination) {
            if ($count >= $this->maxResults) {
                $this->limitExceeded = true;
                break;
            }

            foreach ($this->findNodeTypeOccurrencesInDimensions($nodeTypeName, $dimensionCombination, $this->maxResults - $count, $site) as $node) {
                yield $node;
                $count++;

                if ($count >= $this->maxResults) {
                    $this->limitExceeded = true;
                    break;
                }
            }
        }
    }

    private function findNodeTypeOccurrencesInDimensions(
        string $nodeTypeName,
        array $dimensionValues,
        int $remainingLimit,
        ?Site $site
    ): iterable {
        $contextProperties = [
            'workspaceName' => 'live',
            'dimensions' => $dimensionValues,
            'invisibleContentShown' => true,
        ];
        if ($site !== null) {
            $contextProperties['currentSite'] = $site;
        }
        $context = $this->contextFactory->create($contextProperties);

        if ($site !== null && $context instanceof ContentContext) {
            $startingPoint = $context->getCurrentSiteNode();
        } else {
            $startingPoint = $context->getRootNode();
        }

        if (!$startingPoint instanceof NodeInterface) {
            return;
        }

        yield from (new FlowQuery([$startingPoint]))
            ->find('[instanceof '.$nodeTypeName.']')
            ->slice(0, $remainingLimit)
            ->get();
    }

    private function buildDimensionCombinationLabel(array $dimensionValues): string
    {
        $presets = $this->contentDimensionPresetSource->findPresetsByTargetValues($dimensionValues);

        $labels = [];
        foreach ($dimensionValues as $dimensionName => $values) {
            $preset = $presets[$dimensionName] ?? null;
            $labels[] = $preset['label'] ?? reset($values);
        }

        return implode(', ', $labels);
    }

    private static function serializeDimensionValues(array $dimensionValues): string
    {
        return json_encode($dimensionValues, JSON_THROW_ON_ERROR);
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
