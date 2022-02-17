<?php
declare(strict_types=1);

namespace Netlogix\NodeTypeFinder\Command;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Netlogix\NodeTypeFinder\Service\NodeTypeFinderService;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeFinderCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var NodeTypeFinderService
     */
    protected $nodeTypeFinderService;

    /**
     * List the URLs of all pages where a node of the specified type occurs.
     *
     * @param string $nodeTypeName
     * @param bool $showInvisible
     * @param bool $showRemoved
     * @param bool $showInaccessible
     * @throws \Neos\Eel\Exception
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws \Neos\Neos\Exception
     */
    public function listNodeTypeOccurrencesCommand(
        string $nodeTypeName,
        bool $showInvisible = false,
        bool $showRemoved = false,
        bool $showInaccessible = false
    ): void
    {
        $this->output->outputTable($this->nodeTypeFinderService->findNodeTypeOccurrences(
            $nodeTypeName,
            self::buildControllerContext(),
            $showInvisible,
            $showRemoved,
            $showInaccessible
        ), ['Occurrence on page:', 'Page name:']);
    }

    private static function buildControllerContext(): ControllerContext
    {
        $_SERVER['FLOW_REWRITEURLS'] = '1';
        $httpRequest = ServerRequest::fromGlobals();
        $request = ActionRequest::fromHttpRequest($httpRequest);
        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($request);

        return new ControllerContext(
            $request,
            new ActionResponse(),
            new Arguments([]),
            $uriBuilder
        );
    }
}
