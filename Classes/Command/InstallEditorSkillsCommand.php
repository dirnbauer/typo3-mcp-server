<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

#[AsCommand(
    name: 'mcp:install-editor-skills',
    description: 'Install editor workflow skills for Claude Code / OpenCode into the current project',
)]
final class InstallEditorSkillsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filesystem = new Filesystem();
        $skillsSourcePath = ExtensionManagementUtility::extPath('mcp_server') . 'Resources/Private/Skills';
        $targetPath = getcwd() . '/.claude/skills';

        if (!is_dir($skillsSourcePath)) {
            $output->writeln('<error>Skills source directory not found: ' . $skillsSourcePath . '</error>');

            return Command::FAILURE;
        }

        $filesystem->mkdir($targetPath);
        $filesystem->mirror($skillsSourcePath, $targetPath, options: ['override' => true]);

        $copiedSkills = array_values(array_filter(
            scandir($targetPath) ?: [],
            static fn(string $item): bool => $item !== '.' && $item !== '..' && is_dir($targetPath . '/' . $item),
        ));

        $output->writeln('<info>Editor skills installed to ' . $targetPath . '</info>');
        foreach ($copiedSkills as $skill) {
            $output->writeln('  - ' . $skill);
        }

        return Command::SUCCESS;
    }
}
