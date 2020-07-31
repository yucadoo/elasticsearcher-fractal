<?php

declare(strict_types=1);

namespace YucaDoo\ElasticSearcher\Managers;

use ElasticSearcher\Managers\DocumentsManager as WrappedManager;
use InvalidArgumentException;
use League\Fractal\Manager as FractalManager;
use League\Fractal\Resource\Item as FractalItem;
use League\Fractal\Resource\Collection as FractalCollection;
use League\Fractal\Serializer\ArraySerializer;
use League\Fractal\TransformerAbstract;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

class DocumentManagerTest extends TestCase
{
    /** @var DocumentManager */
    private $documentManager;

    /** @var MockObject|WrappedManager */
    private $wrappedManagerMock;
    /** @var MockObject|FractalManager */
    private $fractalManagerMock;
    /** @var MockObject|DocumentAdapter */
    private $documentAdapterMock;
    /** @var MockObject|ContainerInterface */
    private $transformerRegistryMock;

    public function setUp(): void
    {
        parent::setUp();

        /** @var MockObject|WrappedManager */
        $this->wrappedManagerMock = $this->createMock(WrappedManager::class);
        /** @var MockObject|FractalManager */
        $this->fractalManagerMock = $this->createMock(FractalManager::class);
        /** @var MockObject|DocumentAdapter */
        $this->documentAdapterMock = $this->createMock(DocumentAdapter::class);
        /** @var MockObject|ContainerInterface */
        $this->transformerRegistryMock = $this->createMock(ContainerInterface::class);

        $this->documentManager = new DocumentManager(
            $this->wrappedManagerMock,
            $this->fractalManagerMock,
            $this->documentAdapterMock,
            $this->transformerRegistryMock
        );
    }

    private function expectTransformation(string $indexName, $item, array $data): void
    {
        $dataObject = new ArrayCarrier($data);

        $transformerMock = $this->createMock(TransformerAbstract::class);
        $this->transformerRegistryMock
            ->expects($this->once())
            ->method('get')
            ->with($indexName)
            ->willReturn($transformerMock);

        $this->fractalManagerMock
            ->expects($this->once())
            ->method('createData')
            ->with($this->callback(function (FractalItem $resource) use ($item, $transformerMock) {
                return ($item === $resource->getData()) &&
                    ($transformerMock === $resource->getTransformer());
            }))
            ->willReturn($dataObject);
    }

    private function expectCollectionTransformation(string $indexName, $items, array $data): void
    {
        $dataObject = new ArrayCarrier($data);

        $transformerMock = $this->createMock(TransformerAbstract::class);
        $this->transformerRegistryMock
            ->expects($this->once())
            ->method('get')
            ->with($indexName)
            ->willReturn($transformerMock);

        $this->fractalManagerMock
            ->expects($this->once())
            ->method('createData')
            ->with($this->callback(function (FractalCollection $resource) use ($items, $transformerMock) {
                return ($items === $resource->getData()) &&
                    ($transformerMock === $resource->getTransformer());
            }))
            ->willReturn($dataObject);
    }

    public function testCreateCreatesDocumentWithTransformedItem()
    {
        $item = new stdClass();
        $elasticsearchRespone = ['response'];

        $this->documentAdapterMock
            ->method('getId')
            ->with($item)
            ->willReturn(123);
        $this->documentAdapterMock
            ->method('getIndexName')
            ->with($item)
            ->willReturn('users');

        $this->expectTransformation('users', $item, ['name' => 'Administrator']);

        $this->wrappedManagerMock
            ->expects($this->once())
            ->method('index')
            ->with(
                'users',
                '_doc',
                ['name' => 'Administrator']
            )
            ->willReturn($elasticsearchRespone);

        $response = $this->documentManager->create($item);
        $this->assertEquals($elasticsearchRespone, $response);
    }

    public function testBulkCreateForSameIndexBulkCreatesDocuments()
    {
        $item = new stdClass();
        $otherItem = new stdClass();
        $items = [$item, $otherItem];
        $elasticsearchRespone = ['response'];

        $this->documentAdapterMock
            ->method('getId')
            ->will($this->returnValueMap([
                [$item, 123],
                [$otherItem, 456],
            ]));
        $this->documentAdapterMock
            ->method('getIndexName')
            ->will($this->returnValueMap([
                [$item, 'users'],
                [$otherItem, 'users'],
            ]));

        $this->wrappedManagerMock
            ->expects($this->once())
            ->method('bulkIndex')
            ->with(
                'users',
                '_doc',
                [
                    ['name' => 'Item'],
                    ['name' => 'Other item'],
                ]
            )
            ->willReturn($elasticsearchRespone);

        $this->expectCollectionTransformation('users', $items, [
            'data' => [
                ['name' => 'Item'],
                ['name' => 'Other item'],
            ],
        ]);

        $response = $this->documentManager->bulkCreate($items);
        $this->assertEquals($elasticsearchRespone, $response);
    }

    public function testBulkCreateEmptyCollectionDoesNothing()
    {
        $this->wrappedManagerMock
            ->expects($this->never())
            ->method('bulkIndex');

        $result = $this->documentManager->bulkCreate([]);
        $this->assertEquals([], $result);
    }

    public function testBulkCreateForDifferentIndexThrowsInvalidArgumentException()
    {
        $item = new stdClass();
        $otherItem = new stdClass();
        $items = [$item, $otherItem];

        $this->documentAdapterMock
            ->method('getId')
            ->will($this->returnValueMap([
                [$item, 123],
                [$otherItem, 456],
            ]));
        $this->documentAdapterMock
            ->method('getIndexName')
            ->will($this->returnValueMap([
                [$item, 'users'],
                [$otherItem, 'posts'],
            ]));

        $this->expectException(InvalidArgumentException::class);

        $this->documentManager->bulkCreate($items);
    }

    public function testDeleteDeletesDocumentWithTransformedItem()
    {
        $item = new stdClass();
        $elasticsearchRespone = ['response'];

        $this->documentAdapterMock
            ->method('getId')
            ->with($item)
            ->willReturn(123);
        $this->documentAdapterMock
            ->method('getIndexName')
            ->with($item)
            ->willReturn('users');

        $this->wrappedManagerMock
            ->expects($this->once())
            ->method('delete')
            ->with(
                'users',
                '_doc',
                123
            )
            ->willReturn($elasticsearchRespone);

        $response = $this->documentManager->delete($item);
        $this->assertEquals($elasticsearchRespone, $response);
    }

    public function testUpdateUpdatesDocumentWithTransformedItem()
    {
        $item = new stdClass();
        $elasticsearchRespone = ['response'];

        $this->documentAdapterMock
            ->method('getId')
            ->with($item)
            ->willReturn(123);
        $this->documentAdapterMock
            ->method('getIndexName')
            ->with($item)
            ->willReturn('users');

        $this->expectTransformation('users', $item, ['name' => 'Administrator']);

        $this->wrappedManagerMock
            ->expects($this->once())
            ->method('update')
            ->with(
                'users',
                '_doc',
                123,
                ['name' => 'Administrator']
            )
            ->willReturn($elasticsearchRespone);

        $response = $this->documentManager->update($item);
        $this->assertEquals($elasticsearchRespone, $response);
    }

    /**
     * @dataProvider getBoolValues
     * @param bool $exists Indicates whether document existance is mocked in test
     */
    public function testExists(bool $exists)
    {
        $item = new stdClass();

        $this->documentAdapterMock
            ->method('getId')
            ->with($item)
            ->willReturn(123);
        $this->documentAdapterMock
            ->method('getIndexName')
            ->with($item)
            ->willReturn('users');

        $this->wrappedManagerMock
            ->expects($this->once())
            ->method('exists')
            ->with(
                'users',
                '_doc',
                123
            )
            ->willReturn($exists);

        $result = $this->documentManager->exists($item);
        $this->assertEquals($exists, $result);
    }

    public function testUpdateOrCreateUpdatesExistingDocument()
    {
        $item = new stdClass();
        $elasticsearchRespone = ['response'];

        $this->documentAdapterMock
            ->method('getId')
            ->with($item)
            ->willReturn(123);
        $this->documentAdapterMock
            ->method('getIndexName')
            ->with($item)
            ->willReturn('users');

        $this->wrappedManagerMock
            ->expects($this->once())
            ->method('exists')
            ->with(
                'users',
                '_doc',
                123
            )
            ->willReturn(true);

        $this->expectTransformation('users', $item, ['name' => 'Administrator']);

        $this->wrappedManagerMock
            ->expects($this->once())
            ->method('update')
            ->with(
                'users',
                '_doc',
                123,
                ['name' => 'Administrator']
            )
            ->willReturn($elasticsearchRespone);

        $response = $this->documentManager->updateOrCreate($item);
        $this->assertEquals($elasticsearchRespone, $response);
    }

    public function testUpdateOrCreateCreatesMissingDocument()
    {
        $item = new stdClass();
        $elasticsearchRespone = ['response'];

        $this->documentAdapterMock
            ->method('getId')
            ->with($item)
            ->willReturn(123);
        $this->documentAdapterMock
            ->method('getIndexName')
            ->with($item)
            ->willReturn('users');

        $this->wrappedManagerMock
            ->expects($this->once())
            ->method('exists')
            ->with(
                'users',
                '_doc',
                123
            )
            ->willReturn(false);

        $this->expectTransformation('users', $item, ['name' => 'Administrator']);

        $this->wrappedManagerMock
            ->expects($this->once())
            ->method('index')
            ->with(
                'users',
                '_doc',
                ['name' => 'Administrator']
            )
            ->willReturn($elasticsearchRespone);

        $response = $this->documentManager->updateOrCreate($item);
        $this->assertEquals($elasticsearchRespone, $response);
    }

    public function testGetGetsDocument()
    {
        $item = new stdClass();
        $fetchedDocument = ['name' => 'Administrator'];

        $this->documentAdapterMock
            ->method('getId')
            ->with($item)
            ->willReturn(123);
        $this->documentAdapterMock
            ->method('getIndexName')
            ->with($item)
            ->willReturn('users');

        $this->wrappedManagerMock
            ->expects($this->once())
            ->method('get')
            ->with(
                'users',
                '_doc',
                123
            )
            ->willReturn($fetchedDocument);

        $response = $this->documentManager->get($item);
        $this->assertEquals($fetchedDocument, $response);
    }

    public function getBoolValues(): array
    {
        return [
            [true],
            [false],
        ];
    }
}
