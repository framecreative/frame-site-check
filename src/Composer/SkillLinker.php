<?php

namespace Frame\SiteCheck\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;

/**
 * Links the site-check Claude skill into the site's .claude/skills after every install or update,
 * and removes the link when the package is removed. Not active on `--no-dev` installs.
 */
class SkillLinker implements EventSubscriberInterface, PluginInterface
{
    private const LINK = '.claude/skills/site-check';

    private Composer $composer;

    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $link = $this->root($composer).'/'.self::LINK;

        if (is_link($link)) {
            unlink($link);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'link',
            ScriptEvents::POST_UPDATE_CMD => 'link',
        ];
    }

    public function link(): void
    {
        $root = $this->root($this->composer);
        $package = $this->composer->getRepositoryManager()->getLocalRepository()->findPackage('framecreative/frame-site-check', '*');

        if ($package === null) {
            return;
        }

        $skill = realpath($this->composer->getInstallationManager()->getInstallPath($package)).'/skills/site-check';
        $link = $root.'/'.self::LINK;

        if (file_exists($link) && ! is_link($link)) {
            $this->io->writeError('<warning>site-check: '.self::LINK.' exists and is not a link; leaving it alone.</warning>');

            return;
        }

        $target = '../../'.ltrim(substr($skill, strlen($root)), '/');

        if (is_link($link) && readlink($link) === $target) {
            return;
        }

        @mkdir(dirname($link), 0777, true);
        @unlink($link);
        symlink($target, $link);

        $this->io->write('<info>site-check: linked the Claude skill at '.self::LINK.'</info>');
    }

    private function root(Composer $composer): string
    {
        return realpath(dirname($composer->getConfig()->get('vendor-dir')));
    }
}
