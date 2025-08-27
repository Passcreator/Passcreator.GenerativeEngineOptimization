<?php
declare(strict_types=1);

namespace Passcreator\GenerativeEngineOptimization;

use Neos\Flow\Package\Package as BasePackage;
use Neos\Flow\Core\Bootstrap;
use Neos\ContentRepository\Domain\Model\Workspace;
use Passcreator\GenerativeEngineOptimization\Service\LLMGeneratorService;

class Package extends BasePackage
{
    /**
     * @param Bootstrap $bootstrap
     * @return void
     */
    public function boot(Bootstrap $bootstrap): void
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        $dispatcher->connect(
            Workspace::class,
            'afterNodePublishing',
            LLMGeneratorService::class,
            'handleNodePublished'
        );
    }
}