<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\SyntheticFixtureLoader;
use App\Entity\CanvasElement;
use App\Repository\CanvasElementRepository;
use App\Tests\Controller\ApiFunctionalTestCase;

final class SyntheticFixtureLoaderIntegrationTest extends ApiFunctionalTestCase
{
    public function testConnectorsResolveBothEndpointsAndReloadingCreatesNothing(): void
    {
        /** @var SyntheticFixtureLoader $loader */
        $loader = self::getContainer()->get(SyntheticFixtureLoader::class);

        $stats = $loader->load();
        self::assertGreaterThan(0, $stats['elements']);

        $this->entityManager->clear();
        /** @var CanvasElementRepository $elements */
        $elements = $this->entityManager->getRepository(CanvasElement::class);
        $connectors = $elements->findBy(['type' => 'connector']);
        self::assertNotEmpty($connectors);

        foreach ($connectors as $connector) {
            $metadata = $connector->getMetadata();
            self::assertIsArray($metadata);
            self::assertArrayHasKey('fromId', $metadata);
            self::assertArrayHasKey('toId', $metadata);
            self::assertNotSame($metadata['fromId'], $metadata['toId']);
            self::assertInstanceOf(CanvasElement::class, $elements->find((string) $metadata['fromId']));
            self::assertInstanceOf(CanvasElement::class, $elements->find((string) $metadata['toId']));
        }

        $second = $loader->load();
        self::assertSame(0, array_sum($second));
    }
}
