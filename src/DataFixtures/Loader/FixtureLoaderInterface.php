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

use Symfony\Component\Config\Loader\LoaderInterface;

/**
 * Interface FixtureLoaderInterface.
 */
interface FixtureLoaderInterface extends LoaderInterface
{
    /**
     * @var string
     */
    const RESOURCE_TEXT = 'txt';

    /**
     * @var string
     */
    const RESOURCE_JSON = 'json';

    /**
     * @var string
     */
    const RESOURCE_YAML = 'yml';

    /**
     * @var string
     */
    const RESOURCE_XML = 'xml';
}

/* EOF */
