<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Composer\Autoload\ClassLoader;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverDelegateRegistry;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinitionProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentListProviderInterface;
use TYPO3Fluid\Fluid\Schema\ViewHelperFinder;
use TYPO3Fluid\Fluid\Schema\ViewHelperMetadata;
use TYPO3Fluid\Fluid\Schema\ViewHelperMetadataFactory;

/**
 * Collects Fluid ViewHelper metadata from the Composer project.
 */
final class ViewHelperCatalogService
{
    /**
     * @return list<ViewHelperMetadata>
     */
    public function getAllViewHelpers(): array
    {
        $classLoader = $this->resolveClassLoader();
        $viewHelperFinder = new ViewHelperFinder();
        $viewHelpers = $viewHelperFinder->findViewHelpersInComposerProject($classLoader);

        $viewHelperMetadataFactory = new ViewHelperMetadataFactory();
        $delegateRegistry = GeneralUtility::makeInstance(ViewHelperResolverDelegateRegistry::class);
        foreach ($delegateRegistry->getAll() as $delegate) {
            if (
                $delegate instanceof ComponentListProviderInterface
                && $delegate instanceof ComponentDefinitionProviderInterface
            ) {
                foreach ($delegate->getAvailableComponents() as $componentName) {
                    $viewHelpers[] = $viewHelperMetadataFactory->createFromComponentDefinition(
                        $delegate,
                        $delegate->getComponentDefinition($componentName),
                    );
                }
            }
        }

        usort(
            $viewHelpers,
            static fn(ViewHelperMetadata $a, ViewHelperMetadata $b): int => strcmp($a->tagName, $b->tagName),
        );

        return $viewHelpers;
    }

    public function findByTagName(string $tagName): ?ViewHelperMetadata
    {
        foreach ($this->getAllViewHelpers() as $viewHelper) {
            if ($viewHelper->tagName === $tagName) {
                return $viewHelper;
            }
        }

        return null;
    }

    private function resolveClassLoader(): ClassLoader
    {
        $classLoader = GeneralUtility::makeInstance(ClassLoader::class);
        if ($classLoader instanceof ClassLoader) {
            return $classLoader;
        }

        throw new \RuntimeException('Composer ClassLoader is not available.');
    }
}
