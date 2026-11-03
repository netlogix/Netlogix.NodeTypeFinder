<?php
declare(strict_types=1);

namespace Netlogix\NodeTypeFinder\Command;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Netlogix\NodeTypeFinder\Service\NodeTypeFinderService;
use Psr\Http\Message\UriInterface;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeFinderCommandController extends CommandController
{
    /**
     * @var NodeTypeFinderService
     * @Flow\Inject
     */
    protected $nodeTypeFinderService;

    /**
     * @var ContentRepositoryRegistry
     * @Flow\Inject
     */
    protected $contentRepositoryRegistry;

    /**
     * @var SiteRepository
     * @Flow\Inject
     */
    protected $siteRepository;

    /**
     * @var NodeUriBuilderFactory
     * @Flow\Inject
     */
    protected $nodeUriBuilderFactory;

    /**
     * List the URLs of all pages where a node of the specified type occurs.
     *
     * @param string $nodeTypeName
     * @throws \Neos\Eel\Exception
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws \Neos\Neos\Exception
     */
    public function listNodeTypeOccurrencesCommand(string $nodeTypeName): void
    {
        $this->output->outputTable($this->nodeTypeFinderService->findNodeTypeOccurrences(
            $nodeTypeName,
            $this->buildActionRequest()
        ), ['Occurrence on page:']);
    }

    /**
     * Resolve the serialized node address of a node to a uri.
     */
    public function resolveNodeAddressToUriCommand(string $nodeAddress): void
    {
        $nodeAddress = NodeAddress::fromJsonString($nodeAddress);
        $subgraph = $this->contentRepositoryRegistry->get($nodeAddress->contentRepositoryId)
            ->getContentGraph($nodeAddress->workspaceName)
            ->getSubgraph($nodeAddress->dimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

        $node = $subgraph->findNodeById($nodeAddress->aggregateId);
        $documentNode = $node ? $this->nodeTypeFinderService->findClosestDocumentNode($node, $subgraph) : null;
        if (!$documentNode) {
            $this->outputLine('No document node found for node %s', [$nodeAddress->aggregateId->value]);
            $this->quit(1);
        }
        $visible = $this->nodeTypeFinderService->isNodeVisible($documentNode);
        $nodeUriBuilder = $this->nodeUriBuilderFactory->forActionRequest($this->buildActionRequest());
        $uri = $this->nodeTypeFinderService->buildNodeUri($documentNode, $nodeUriBuilder, $visible);
        if ($uri === null) {
            $this->outputLine('No URI found for node %s', [$nodeAddress->aggregateId->value]);
            $this->quit(1);
        }

        $this->outputLine($uri);
    }

    private function buildActionRequest(): ActionRequest
    {
        $site = $this->siteRepository->findDefault();
        $uri = new Uri((string)$site->getPrimaryDomain() ?: 'http://localhost');
        $httpRequest = SiteDetectionResult::create($site->getNodeName(), $site->getConfiguration()->contentRepositoryId)
            ->storeInRequest(self::createHttpRequestFromGlobals($uri));

        return ActionRequest::fromHttpRequest($httpRequest);
    }

    private static function createHttpRequestFromGlobals(UriInterface $uri): ServerRequest
    {
        $_SERVER['FLOW_REWRITEURLS'] = '1';
        $fromGlobals = ServerRequest::fromGlobals();

        return new ServerRequest(
            $fromGlobals->getMethod(),
            $uri,
            $fromGlobals->getHeaders(),
            $fromGlobals->getBody(),
            $fromGlobals->getProtocolVersion(),
            array_merge(
                $fromGlobals->getServerParams(),
                // Empty SCRIPT_NAME to prevent "./flow" in Uri
                [
                    'SCRIPT_NAME' => '',
                ]
            )
        );
    }
}
