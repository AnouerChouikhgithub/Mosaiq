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

    public function testReloadRepairsOnlyConnectorIdsAndPreservesOtherMetadata(): void
    {
        /** @var SyntheticFixtureLoader $loader */
        $loader = self::getContainer()->get(SyntheticFixtureLoader::class);
        $loader->load();

        $this->entityManager->clear();
        /** @var CanvasElementRepository $elements */
        $elements = $this->entityManager->getRepository(CanvasElement::class);
        $connector = $elements->findOneBy(['type' => 'connector']);
        self::assertInstanceOf(CanvasElement::class, $connector);
        $connectorId = $connector->getId();
        $expected = $connector->getMetadata();
        self::assertIsArray($expected);

        // Simulate a stale row: one wrong id, one missing id and unrelated metadata.
        $connector->updateStyle($connector->getColor(), [
            'fromId' => 'stale-id',
            'custom' => 'keep-me',
        ]);
        $this->entityManager->flush();

        self::assertSame(0, array_sum($loader->load()));

        $this->entityManager->clear();
        $repaired = $elements->find($connectorId);
        self::assertInstanceOf(CanvasElement::class, $repaired);
        $metadata = $repaired->getMetadata();
        self::assertIsArray($metadata);
        self::assertSame($expected['fromId'], $metadata['fromId']);
        self::assertSame($expected['toId'], $metadata['toId']);
        self::assertSame('keep-me', $metadata['custom']);
    }
}
