<?php

/*
 * This file is part of the Scribe Arthur Doctrine Fixtures Library.
 *
 * (c) Scribe Inc. <oss@scr.be>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Scribe\Arthur\DoctrineFixturesBundle\DataFixtures\Loader;

use Symfony\Component\Config\Loader\LoaderResolverInterface;

/**
 * Interface FixtureLoaderResolverInterface.
 */
interface FixtureLoaderResolverInterface extends LoaderResolverInterface
{
    /**
     * @param FixtureLoaderInterface[] $loaders
     *
     * @return $this
     */
    public function assignLoaders(array $loaders = []);
}

/* EOF */
