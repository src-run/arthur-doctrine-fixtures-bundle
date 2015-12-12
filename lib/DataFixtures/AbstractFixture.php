<?php

/*
 * This file is part of the Scribe Arthur Doctrine Fixtures Library.
 *
 * (c) Scribe Inc. <oss@scr.be>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Scribe\Doctrine\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture as BaseAbstractFixture;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Scribe\Doctrine\DataFixtures\Exception\StrategyException;
use Scribe\Doctrine\DataFixtures\Loader\YamlFixtureLoader;
use Scribe\Doctrine\DataFixtures\Loader\FixtureLoaderResolver;
use Scribe\Doctrine\DataFixtures\Locator\FixtureLocator;
use Scribe\Doctrine\DataFixtures\Metadata\FixtureMetadata;
use Scribe\Doctrine\DataFixtures\Metadata\FixtureMetadataInterface;
use Scribe\Doctrine\DataFixtures\Paths\FixturePaths;
use Scribe\Doctrine\Exception\ORMException;
use Scribe\Doctrine\ORM\Mapping\Entity;
use Scribe\Wonka\Component\Hydrator\Manager\HydratorManager;
use Scribe\Wonka\Component\Hydrator\Mapping\HydratorMapping;
use Scribe\Wonka\Console\OutBuffer;
use Scribe\Wonka\Exception\LogicException;
use Scribe\Wonka\Exception\RuntimeException;
use Scribe\Wonka\Utility\Reflection\ClassReflectionAnalyser;
use Scribe\Wonka\Utility\ClassInfo;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Class AbstractFixture.
 */
abstract class AbstractFixture extends BaseAbstractFixture implements FixtureInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * Holds interpreted fixture data.
     *
     * @var FixtureMetadata
     */
    protected $metadata;

    /**
     * Number of items to batch when flushing Doctrine.
     *
     * @var int
     */
    protected $insertFlushBatchSize = 1000;

    /**
     * Regular expression to parse class name to translate to fixture data filename.
     *
     * @var string
     */
    protected $fixtureSearchNameRegex = '';

    /**
     * Using fixture search regex for class name, determine fixture name via template.
     *
     * @var string
     */
    protected $fixtureNameTemplate = '%name%Data.%type%';

    /**
     * Dynamically resolved namespace of fixture entity.
     *
     * @var bool|string
     */
    protected $entityNamespace = false;

    /**
     * @var null|bool
     */
    protected $skip;

    /**
     * @var array
     */
    protected $identities = [];

    /**
     * Array of arrays containing [arts of a filepath to be combined at runtime (cartesian product).
     *
     * @var array[]
     */
    protected $fixtureSearchPathParts = [
        ['../', '../../', '../../../'],
        ['app/config', './'],
        ['config', 'shared_public', 'shared_proprietary'],
        ['fixtures'],
    ];

    /**
     * {@inherit-doc}.
     *
     * @return string
     */
    abstract public function getType();

    /**
     * {@inherit-doc}.
     *
     * @param ContainerInterface $container
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;

        $this->loadFixtureMetadata();
    }

    /**
     * {@inherit-doc}.
     *
     * @param string $regex
     */
    public function setFixtureFileSearchRegex($regex)
    {
        $this->fixtureSearchNameRegex = $regex;
    }

    /**
     * {@inherit-doc}.
     *
     * @return Paths\FixturePaths
     */
    public function getFixtureFileSearchPaths()
    {
        return FixturePaths::create()->cartesianProductFromPaths(
            [$this->container->getParameter('kernel.root_dir')],
            ...$this->fixtureSearchPathParts
        );
    }

    /**
     * {@inherit-doc}.
     *
     * @param array[] ...$paths
     */
    public function setFixtureFileSearchPaths(array ...$paths)
    {
        $this->fixtureSearchPathParts = $paths ?: [];
    }

    /**
     * {@inherit-doc}.
     *
     * @return Loader\FixtureLoaderInterface[]
     */
    public function getFixtureFileLoaders()
    {
        return [new YamlFixtureLoader()];
    }

    /**
     * {@inherit-doc}.
     *
     * @throws RuntimeException
     *
     * @return $this
     */
    public function loadFixtureMetadata()
    {
        try {
            $locator = new FixtureLocator();
            $locator->setPaths($this->getFixtureFileSearchPaths());

            $loader = new FixtureLoaderResolver();
            $loader->assignLoaders($this->getFixtureFileLoaders());

            $metadata = new FixtureMetadata();
            $metadata
                ->setNameRegex($this->fixtureSearchNameRegex)
                ->setNameTemplate($this->fixtureNameTemplate)
                ->setHandler($this)
                ->setLocator($locator)
                ->setLoader($loader)
                ->load();

            $this->metadata = $metadata;
        } catch (\Exception $exception) {
            throw new RuntimeException('Unable to generate metadata for fixture (ORM Loader: %s)', null, $exception, get_class($this));
        }

        return $this;
    }

    /**
     * @param ObjectManager $manager
     */
    public function load(ObjectManager $manager)
    {
        echo PHP_EOL;
        $this->objectManager = $manager;

        $this->checkVersions();

        if ($this->skip === true) {
            OutBuffer::stat('+y/i-runmode +y/b-[ended]+w/- previous error set mode to skip');
            echo PHP_EOL;
            return;
        }

        if ($this->metadata->isEmpty()) {
            OutBuffer::stat('+y/i-runmode +y/b-[ended]+w/- empty data set provided by fixture');
            echo PHP_EOL;
            return;
        }

        $shortDepNameList = function ($fullyQualifiedDependencies) {
            $dependencyList = [];

            foreach ($fullyQualifiedDependencies as $d) {
                $dependencyList[] = substr(preg_replace('{.+Load}', '', $d), 0, -4);
            }

            return implode(',', $dependencyList);
        };

        if (method_exists($this, 'getDependencies')) {
            OutBuffer::stat('+g/i-depends+g/b- [rdeps]+w/- ordered by dependencies=[ +w/i-'.($shortDepNameList($this->getDependencies())).' +w/-]');
        } elseif (method_exists($this, 'getOrder')) {
            OutBuffer::stat('+g/i-depends+g/b- [order]+w/- ordered by priority=[ +w/i-'.$this->getOrder().' +w/-]');
        }

        foreach(['prefer', 'fallback', 'failure'] as $attemptType) {
            try {
                list($persistMode, $cleanupMode) = $this->resolveRuntimeMode($attemptType);
                $this->performLoad($attemptType, $persistMode, $cleanupMode);
            } catch (StrategyException $e) {
                $this->performFailure($e->getMessage());
                continue;
            }

            break;
        }

        echo PHP_EOL;
    }

    protected function performFailure($cause)
    {
        OutBuffer::stat('+y/i-runmode +y/b-[warns]+w/- not importing=[ %s ]+w/- cause=[ %s ]', $this->resolveEntityFqcn(), $cause);

        return false;
    }

    /**
     * @throws \Exception
     */
    protected function performLoad($for, $persistMode, $cleanupMode)
    {
        $countInsert = $countUpdate = $countSkip = $countPurge = 0;
        $dataFixtures = $this->metadata->getData();
        $countFixtures = count($dataFixtures);

        if ($persistMode === FixtureMetadata::MODE_SKIP) {
            throw new StrategyException('intentional skip');
        }

        $this->performModePreLoad($persistMode, $countPurge);

        if ($persistMode === FixtureMetadata::MODE_SKIP) {
            throw new StrategyException('intentional skip');
        }

        OutBuffer::stat('+g/i-persist +g/b-[start]+w/- persisting fixtures to orm=[ +w/i-'.$countFixtures.' found +w/-]');

        $this->entityManagerFlushAndClean();

        foreach ($dataFixtures as $index => $data) {
            $this->entityPopulateNewObj($index, $data, $entity);

            if ($persistMode === FixtureMetadata::MODE_PURGE || $persistMode === FixtureMetadata::MODE_BLIND) {
                $this->entityLoadAndPersist($entity, $countInsert);
            } else {
                $this->entityLoadAndDoMerge($index, $entity, $countUpdate, $countSkip);
            }

            $this->entityHandleCannibal($entity);
            $this->entityResolveAllRefs($entity, $index, $data);

            if (($index % $this->insertFlushBatchSize) === 0) {
                $this->entityManagerFlushAndClean();
            }
        }

        $this->entityManagerFlushAndClean();

        $this->entityCleanup($persistMode, $cleanupMode, $countPurge);

        OutBuffer::stat(
            '+g/i-persist +g/b-[ended]+w/- stats=[ +w/i-'.
            ($countPurge ?: 0).' purges +w/-|+w/i- '.($countUpdate ?: 0).' updates +w/-|+w/i- '.
            ($countInsert ?: 0).' inserts +w/-|+w/i- '.($countSkip ?: 0).
            ' skips +w/-]+w/- totals=+w/-[+w/b- '.
            ($countSkip + $countUpdate + $countInsert).' +w/i-of+w/b- '.$countFixtures.' +w/i-fixtures managed +w/-]'
        );
    }

    protected function entityCleanup($persistMode, $cleanupMode, &$countPerge)
    {
        if ($persistMode === FixtureMetadata::MODE_PURGE) {
            return;
        }

        array_walk($this->identities, function(&$identity){
            $identity = current($identity);
        });

        $repo = $this->objectManager->getRepository($this->resolveEntityFqcn());
        $all = $repo->findAll();
        $remove = [];

        foreach ($all as $entity) {
            if (!in_array($entity->getIdentity(), $this->identities)) {
                $remove[] = $entity;
            }
        }

        OutBuffer::stat('+g/i-removal +g/b-[start]+w/- removing extra data entries=[ +w/i-'.count($remove).' found +w/-]');

        for ($i = 0; $i < count($remove); $i++) {
            $this->objectManager->remove($entity);
            $countPerge++;

            if ($i % $this->insertFlushBatchSize) {
                $this->objectManager->flush();
                $this->objectManager->clear();
            }
        }

        unset($remove);
    }

    /**
     * @return $this
     */
   protected function checkVersions()
   {
       try {
           list($v_struct, $v_data) = $this->metadata->getVersions();
           $v_current = FixtureMetadataInterface::VERSION;
           $v_current_major = substr($v_current, 0, 1);

           if (version_compare($v_struct, $v_current_major, '<')) {
               throw new LogicException('Fixture cannot have a lower structure major-version than running implementation of '.$v_current_major.') but a value of '.$v_struct.' was reported!');
           }

           if (version_compare($v_struct, $v_current, '>')) {
               throw new LogicException('Fixture cannot have a greater structure major-version than running implementation of '.$v_current.') but a value of '.$v_struct.' was reported!');
           }

           OutBuffer::stat('+g/i-version+g/b- [check]+w/- implementation min/max=[ +w/i-'.$v_current_major.'.0.0/'.$v_current.' +w/-] fixture=[ +w/i-'.$v_struct.' +w/-]');
       } catch (\Exception $e) {
           OutBuffer::stat('+y/i-version+y/b- [check]+w/- implementation min/max=[ +w/i-'.$v_current_major.'.0.0/'.$v_current.' +w/-] fixture=[ +w/i-'.$v_struct.' +w/-]');
           $this->skip = true;
       }

       return $this;
   }

    /**
     * @param string|null $for
     *
     * @return bool
     */
    protected function resolveRuntimeMode($for = null)
    {
        $persistMode = $this->metadata->getMode();
        $cleanupMode = $this->metadata->getCleanupMode();
        $modes = ['persist' => $persistMode, 'cleanup' => $cleanupMode];
        $status = '+g/i-runmode+g/b- [start]+w/- using strategy=[ +w/i-%s +w/-] for=[ +w/i-%s +w/-] ';
        $normalized = [];

        foreach($modes as $i => $s) {
            $tmp = [];
            foreach ($s as $type => $mode) {
                if ($for !== null && $for !== $type) {
                    continue;
                }

                switch ($mode) {
                    case FixtureMetadata::MODE_BLIND:
                    case FixtureMetadata::MODE_PURGE:
                    case FixtureMetadata::MODE_MERGE:
                    case FixtureMetadata::MODE_SKIP:
                        $tmp[$type] = $this->normalizeStrategy($i, $mode);
                        break;

                    default:
                        $tmp[$type] = $this->normalizeStrategy($i, FixtureMetadata::MODE_DEFAULT);
                        break;
                }
            }

            if ($for !== null) {
                $normalized[] = $tmp[$for];
            } else {
                $normalized[] = $tmp;
            }
        }

        $modesForString = function($normalized) use ($modes) {
            $r = [];

            for ($i = 0; $i < count($normalized); $i++) {
                if (is_array($normalized[$i])) {
                    $r[] = implode(':', array_values($normalized[$i]));
                } else {
                    $r[] = $normalized[$i];
                }
            }

            return $r;
        };

        $r = $modesForString($normalized);

        OutBuffer::stat($status,
            implode(',', $r),
            implode(',', (array)array_keys($modes))
        );

        return $normalized;
    }

    protected function normalizeStrategy($for, $strategy)
    {
        return $strategy;
    }

    /**
     * @param string $mode
     *
     * @return bool
     */
    protected function performModePreLoad(&$mode, &$purgeCount)
    {
        $entityFqcn = $this->resolveEntityFqcn();
        list($entityMeta, $identityField, $identityNatural) = $this->resolveEntityMeta();

        if ($mode === FixtureMetadata::MODE_MERGE && !$identityNatural) {
            OutBuffer::stat('+y/i-runmode +y/b-[merge]+w/- import strategy unavailable for non-natural entities');
            throw new StrategyException('invalid import mode for entity type');
        }

        $entityRepo = $this->objectManager->getRepository($entityFqcn);
        $entityAssociations = $entityMeta->getAssociationNames();

        if ($mode === FixtureMetadata::MODE_PURGE && count($entityAssociations) > 0) {
            foreach ($entityAssociations as $a) {
                if ($entityMeta->isAssociationInverseSide($a)) {
                    OutBuffer::stat('+y/i-runmode +y/b-[purge]+w/- import strategy unavailable as entity inverses %s',
                        $entityMeta->getAssociationTargetClass($a));
                    $mode = FixtureMetadata::MODE_SKIP;

                    return false;
                }
            }
        }

        if ($mode === FixtureMetadata::MODE_PURGE) {
            $this->performModePurgePreLoad($entityMeta, $entityRepo, $this->objectManager, $purgeCount);
        }

        return true;
    }

    /**
     * @param ClassMetadataInfo $meta
     * @param EntityRepository  $repo
     * @param EntityManager     $em
     */
    protected function performModePurgePreLoad(ClassMetadataInfo $meta, EntityRepository $repo, EntityManager $em, &$purgeCount)
    {
        $deletions = $repo->findAll();

        OutBuffer::stat('+g/b-preload [purge]+w/- truncating previous entities=[ +w/i-%d found +w/-]', count($deletions));

        foreach ($deletions as $d) {
            $em->remove($d);
            ++$purgeCount;
        }

        $em->flush();

        foreach ($deletions as $d) {
            $em->clear($d);
        }

        unset($deletions);
    }

    /**
     * @return mixed[]
     */
    protected function resolveEntityMeta()
    {
        try {
            $entityMeta = $this
                ->objectManager
                ->getClassMetadata($this->entityNamespace);

            $identityNatural = $entityMeta->isIdentifierNatural();
            $identityField = $entityMeta->getSingleIdentifierFieldName();

            return [$entityMeta, $identityField, $identityNatural];
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not get entity metadata/identity information.');
        }
    }

    /**
     * @return bool|string
     */
    protected function resolveEntityFqcn()
    {
        $tmp = $this->entityNamespace;

        if (!$this->entityNamespace &&
            false === $this->resolveEntityFqnsFast() &&
            false === $this->resolveEntityFqnsSlow()) {
            throw new RuntimeException('Could not resolve namespace for entity associated with '.$this->metadata->getName());
        }

        if ($tmp !== $this->entityNamespace) {
            OutBuffer::stat('+g/i-reflect+g/b- [paths]+w/- entity fqcn=[+w/i- '.$this->entityNamespace.' +w/-]');
        }

        return $this->entityNamespace;
    }

    /**
     * @return $this
     */
    protected function resolveEntityFqnsFast()
    {
        $managedNamespaces = $this->objectManager
            ->getConfiguration()
            ->getEntityNamespaces();

        //OutBuffer::stat('+g/i-reflect+g/b- [paths]+w/- resolving entity fully qualified class name');
        foreach ($managedNamespaces as $namespace) {
            if (class_exists($resolvedNamespace = $namespace.'\\'.$this->metadata->getName())) {
                $this->entityNamespace = $resolvedNamespace;

                return true;
            }
        }

        return false;
    }

    /**
     * @return $this
     */
    protected function resolveEntityFqnsSlow()
    {
        $selfRootName = ClassInfo::getNamespaceSetByInstance($this);
        $selfRootName = array_shift($selfRootName);
        $resolvedNamespace = null;

        $resolverSearchDir = $this
                ->container
                ->getParameter('kernel.root_dir').'/../';

        $resolverResolverS = function (\SplFileInfo $file) {
            if (1 === preg_match('{^namespace ([^\s\n;]+)}im', file_get_contents($file->getPathname()), $matches)) {
                $fqcn = $matches[1].'\\'.$this->metadata->getName();

                return class_exists($fqcn) ? $fqcn : false;
            }
        };

        OutBuffer::stat('+y/i-reflect +y/b-[paths]+w/- resolving entity fully qualified class name using fallback (slow) routine');

        $fs = Finder::create()
            ->followLinks()
            ->name($this->metadata->getName().'.php')
            ->in(realpath($resolverSearchDir));

        foreach ($fs as $f) {
            if (($resolvedNamespace = $resolverResolverS($f)) && substr($resolvedNamespace, 0, strlen($selfRootName)) === $selfRootName) {
                $this->entityNamespace = $resolvedNamespace;

                return true;
            }
        }

        return false;
    }

    /**
     * @return $this
     */
    protected function entityManagerFlushAndClean()
    {
        $this->objectManager->flush();
        $this->objectManager->clear();

        return $this;
    }

    /**
     * @param int|mixed $index
     * @param array[]   $data
     * @param $entity   mixed
     *
     * @throws \Exception
     *
     * @return \Scribe\Doctrine\ORM\Mapping\Entity|mixed
     */
    protected function entityPopulateNewObj($index, $data, &$entity)
    {
        try {
            $entity = $this->getNewPopulatedEntity($index, $data);
        } catch (\Exception $e) {
            throw $e;
        }

        return $entity;
    }

    /**
     * @param \Scribe\Doctrine\ORM\Mapping\Entity|mixed $entity
     * @param int                                       $countInsert
     *
     * @throws \Exception
     *
     * @return $this
     */
    protected function entityLoadAndPersist(&$entity, &$countInsert)
    {
        try {
            $this->objectManager->persist($entity);
            ++$countInsert;
        } catch (\Exception $e) {
            throw $e;
        }

        return $this;
    }

    /**
     * @param \Scribe\Doctrine\ORM\Mapping\Entity|mixed $entity
     * @param mixed                                     $index
     * @param int                                       $countUpdate
     * @param int                                       $countSkip
     *
     * @throws \Exception
     *
     * @return $this
     */
    protected function entityLoadAndDoMerge($index, &$entity, &$countUpdate, &$countSkip)
    {
        try {
            $entityMetadata = $this
                ->objectManager
                ->getClassMetadata(get_class($entity));

            $this->identities[] = $identity = $entityMetadata->getIdentifierValues($entity);

            if (count($identity) > 0) {
                $identity = [key($identity) => current($identity)];
            } elseif (!$entity->hasIdentity()) {
                OutBuffer::stat('+y/b-preload +y/i-[warns]+w/- import could not begin for "%s:%d"',
                    basename($this->metadata->getName()), $index);
                OutBuffer::stat('+y/b-preload +y/i-[warns]+w/- import strategy "merge" unavailable due to failed identifier map resolution');
            }

            $entitySearched = $this
                ->objectManager
                ->getRepository(get_class($entity))
                ->findOneBy($identity);

            $this->objectManager->initializeObject($entitySearched);

            if ($entitySearched && !$entity->isEqualTo($entitySearched)) {
                $mapper = new HydratorManager(new HydratorMapping(true));
                $entity = $mapper->getMappedObject($entity, $entitySearched);
                $this->objectManager->remove($entitySearched);
                $this->objectManager->merge($entity);
                $this->objectManager->persist($entity);
                $this->objectManager->persist($entity);
                $this->objectManager->flush();
                ++$countUpdate;
            } elseif ($entitySearched && $entity->isEqualTo($entitySearched)) {
                $entity = $entitySearched;
                ++$countSkip;

                return $this;
            }

            $this->entityLoadAndPersist($entity, $countNotTracked);
        } catch (\Exception $e) {
            throw $e;
        }

        return $this;
    }

    /**
     * @param \Scribe\Doctrine\ORM\Mapping\Entity|mixed $entity
     *
     * @return $this
     */
    protected function entityHandleCannibal(&$entity)
    {
        if ($this->metadata->isCannibal()) {
            $this->objectManager->flush();
        }

        return $this;
    }

    /**
     * @param \Scribe\Doctrine\ORM\Mapping\Entity|mixed $entity
     * @param int|mixed                                 $index
     * @param array[]                                   $data
     *
     * @return $this
     */
    protected function entityResolveAllRefs(&$entity, $index, $data)
    {
        if ($this->metadata->hasReferenceByIndexEnabled()) {
            $this->addReference($this->metadata->getName().':'.$index, $entity);
        }

        if ($this->metadata->hasReferenceByColumnsEnabled()) {
            $referenceByColumnsSetConcat = function ($columns) use ($data) {
                array_walk($columns, function (&$c) use ($data) { $c = $data[$c]; });

                return implode(':', (array) $columns);
            };

            $referenceByColumnsSetRegister = function ($columns) use ($entity, $referenceByColumnsSetConcat) {
                $this->addReference($this->metadata->getName().':'.$referenceByColumnsSetConcat($columns), $entity);
            };

            array_map($referenceByColumnsSetRegister, $this->metadata->getReferenceByColumnsSets());
        }

        return $this;
    }

    /**
     * @param int     $index
     * @param array[] $values
     *
     * @return \Scribe\Doctrine\ORM\Mapping\Entity|mixed
     */
    protected function getNewPopulatedEntity($index, $values)
    {
        try {
            $entityPath = $this->container->getParameter($this->metadata->getServiceKey());
            $entity = new $entityPath();
        } catch (\Exception $exception) {
            throw new RuntimeException('Unable to locate service id %s.', null, $exception, $this->metadata->getServiceKey());
        }

        try {
            return $this->hydrateEntity($entity, $index, $values);
        } catch (\Exception $exception) {
            throw new RuntimeException('Could not hydrate entity: fixture %s, index %s.', null, $exception, $this->metadata->getName(), (string) $index);
        }
    }

    /**
     * @param \Scribe\Doctrine\ORM\Mapping\Entity|mixed $entity
     * @param int|mixed                                 $index
     * @param array[]                                   $values
     *
     * @return \Scribe\Doctrine\ORM\Mapping\Entity|mixed
     */
    protected function hydrateEntity(Entity $entity, $index, $values)
    {
        foreach ($values as $property => $value) {
            $methodCall = $this->getHydrateEntityMethodCall($property);
            $methodData = $this->getHydrateEntityMethodData($property, $values);

            try {
                $entity = $this->hydrateEntityData($entity, $property, $methodCall, $methodData);
            } catch (\Exception $exception) {
                $entity = $this->hydrateEntityData($entity, $property, $methodCall, new ArrayCollection((array) $methodData));
            }
        }

        return $entity;
    }

    /**
     * @param \Scribe\Doctrine\ORM\Mapping\Entity|mixed $entity
     * @param string                                    $property
     * @param string                                    $methodCall
     * @param mixed                                     $methodData
     *
     * @return \Scribe\Doctrine\ORM\Mapping\Entity|mixed
     */
    protected function hydrateEntityData(Entity $entity, $property, $methodCall, $methodData)
    {
        try {
            $reflectProp = (new ClassReflectionAnalyser(new \ReflectionClass($entity)))
                ->setPropertyPublic($property);
            $reflectProp->setValue($entity, $methodData);

            return $entity;
        } catch (\Exception $exception) {
            throw new RuntimeException('Could not assign property "%s" via property, setter or reflection in fixture %s.', null, $exception, $property, $this->metadata->getName());
        }
    }

    /**
     * @param string $property
     *
     * @return string
     */
    protected function getHydrateEntityMethodCall($property)
    {
        return (string) sprintf('set%s', ucfirst($property));
    }

    /**
     * @param string     $property
     * @param array|null $values
     *
     * @return array|mixed
     */
    protected function getHydrateEntityMethodData($property, array $values = null)
    {
        if (!array_key_exists($property, $values)) {
            throw new RuntimeException('Could not find index %s in fixture %s.', null, null, $property, $this->metadata->getName());
        }

        if (is_array($values[$property])) {
            return $this->getHydrationValueSet($values[$property]);
        }

        return $this->getHydrationValue($values[$property]);
    }

    /**
     * @param array $valueSet
     *
     * @return array
     */
    protected function getHydrationValueSet(array $valueSet = [])
    {
        return (array) array_map([$this, 'getHydrationValue'], $valueSet);
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    protected function getHydrationValue($value)
    {
        if (substr($value, 0, 2) === '++') {
            $value = $this->getHydrationValueUsingInternalRefLookup(substr($value, 2)) ?: $value;
        } elseif (substr($value, 0, 1) === '+' && 1 === preg_match('{^\+([a-z]+:[0-9]+)$}i', $value, $matches)) {
            $value = $this->getHydrationValueUsingInternalRefLookup($matches[1]) ?: $value;
        } elseif (substr($value, 0, 1) === '@' && 1 === preg_match('{^@([a-z]+)\?([^=]+)=([^&]+)$}i', $value, $matches)) {
            $value = $this->getHydrationValueUsingSearchQuery($matches[1], $matches[2], $matches[3]) ?: $value;
        }

        return $value;
    }

    /**
     * @param string $reference
     *
     * @return mixed|null
     */
    protected function getHydrationValueUsingInternalRefLookup($reference)
    {
        return $this->getReference($reference);
    }

    /**
     * @param string $dependencyLookup
     * @param string $column
     * @param string $criteria
     *
     * @throws ORMException
     *
     * @return mixed|null
     */
    protected function getHydrationValueUsingSearchQuery($dependencyLookup, $column, $criteria)
    {
        if (!($dependency = $this->metadata->getDependency($dependencyLookup)) || !(isset($dependency['repository']))) {
            throw new RuntimeException('Missing dependency repo config for %s as called in fixture %s.', null, null, $dependencyLookup, $this->metadata->getName());
        }

        if (!$this->container->has($dependency['repository'])) {
            throw new RuntimeException('Dependency %s for fixture %s cannot be found in container.', null, null, $dependencyLookup, $this->metadata->getName());
        }

        $repo = $this->container->get($dependency['repository']);
        $call = isset($dependency['findMethod']) ? $dependency['findMethod'] : 'findBy'.ucwords($column);

        try {
            $result = call_user_func([$repo, $call], $criteria);
        } catch (\Exception $exception) {
            throw new ORMException('Error searching with call %s(%s) in fixture %s.', null, $exception, $call, $criteria, $this->metadata->getName());
        }

        if (count($result) > 1) {
            throw new ORMException('Search with call %s(%s) in fixture %s has >1 result.', null, null, $call, $criteria, $this->metadata->getName());
        }

        if ($result instanceof ArrayCollection) {
            return $result->first();
        }

        return is_array($result) ? array_values($result)[0] : $result;
    }
}

/* EOF */
