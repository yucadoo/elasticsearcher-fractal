# ElasticSearcher Fractal

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Build Status][ico-travis]][link-travis]
[![Coverage Status][ico-scrutinizer]][link-scrutinizer]
[![Quality Score][ico-code-quality]][link-code-quality]
[![Total Downloads][ico-downloads]][link-downloads]

Combines [Elasticsearcher](https://github.com/madewithlove/elasticsearcher) with PHP League's [Fractal package](https://github.com/thephpleague/fractal) for easier document management.

This package is compliant with [PSR-1], [PSR-2], [PSR-4] and [PSR-11]. If you notice compliance oversights,
please send a patch via pull request.

[PSR-1]: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-1-basic-coding-standard.md
[PSR-2]: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md
[PSR-4]: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader.md
[PSR-11]: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-11-container.md

## Install

Via Composer

``` bash
$ composer require yucadoo/elasticsearcher-fractal
```

## Usage

This package provides the `YucaDoo\ElasticSearcher\Managers\DocumentManager` class which can be used instead of the original `ElasticSearcher\Managers\DocumentsManager` class. It actually wraps the original manager, providing the same functionality in a more reusable and object friendly way.

The original document manager handles raw documents, which are arrays. You always have to specify the Elasticsearch index name and id alongside the document. The new document manager is capable of taking any type of input, for example database models. The Elasticsearch index name and id are determined by the given input, while PHP League's [Fractal package](https://github.com/thephpleague/fractal) is used to convert the input to a document. If you like what you see below, this package is what you were looking for!

``` php
<?php

use App\Models\User;

// Implementation of the functions createWrappedDocumentManager() and createNewDocumentManager() is discussed later.
// $originalDocumentManager = createWrappedDocumentManager();
$newDocumentManager = createNewDocumentManager($frameworkContainer);

$user = new User(['id' => 123, 'name' => 'Administrator']);

// Move transformation to fractal transformer
// $data = ['id' => $user->id, 'name' => $user->name];

// $originalDocumentManager->index('users', '_doc', $data);
$newDocumentManager->create($user);
//$originalDocumentManager->bulkIndex('users', '_doc', [$data, $data, $data]);
$newDocumentManager->bulkCreate([$user, $user, $user]);
//$originalDocumentManager->update('users', '_doc', 123, ['name' => 'Moderator']);
$user->name = 'Moderator';
$newDocumentManager->update($user);
//$originalDocumentManager->updateOrIndex('users', '_doc', 123, ['name' => 'Super User']);
$user->name = 'Super User';
$newDocumentManager->updateOrCreate($user);
//$originalDocumentManager->delete('users', '_doc', 123);
$newDocumentManager->delete($user);
//$originalDocumentManager->exists('users', '_doc', 123);
$newDocumentManager->exists($user);
//$originalDocumentManager->get('users', '_doc', 123);
$newDocumentManager->get($user);
```

The new document manager requires an adapter, which extends the `YucaDoo\ElasticSearcher\Managers\DocumentAdapter` interface. The adapter is used to obtain the Elasticsearch index name and id. Below is a sample implementation for Eloquent models.

``` php
<?php

namespace App\ElasticSearcher;

use YucaDoo\ElasticSearcher\Managers\DocumentAdapter;

class EloquentDocumentAdapter implements DocumentAdapter {
    /**
     * Get Elasticsearch id for Eloquent model.
     * @param \Illuminate\Database\Eloquent\Model $item Eloquent model.
     * @return string|int Elasticsearch id.
     */
    public function getId($item)
    {
        return $item->getKey();
    }

    /**
     * Get index name for Eloquent model.
     * @param \Illuminate\Database\Eloquent\Model $item Eloquent model.
     * @return string Elasticsearch index name.
     */
    function getIndexName($item): string
    {
        // Elasticsearch index has the same name as database table.
        return $item->getTable();
    }
}
```

Then implement the transformers for Elasticsearch. An example is given below.

``` php
<?php

namespace App\ElasticSearcher\Transformers;

use App\Models\User;
use League\Fractal\TransformerAbstract;

class UserElasticsearchTransformer extends TransformerAbstract
{
    /**
     * Transform user into Elasticsearch document.
     *
     * @param User $user User to be converted into Elasticsearch document.
     * @return array Generated Elasticsearch document.
     */
    public function transform(User $user)
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }
}
```

It's time now to put things together. To do so we need a PSR-11 compatible container, which exists in most modern PHP frameworks. Most containers return an instance of the class when given the full class name.

The document manager needs the container to obtain the transformer instance to be used for the handled input. When handling a User a UserElasticsearchTransformer instance is needed, when handling a Post model the PostElasticsearchTransformer instance is needed, and so on.
The document manager doesn't know the class name of the transformer. The container is expect to resolve the transformer based on the index name. To do so the [AliasContainer](https://github.com/thecodingmachine/alias-container) can be used (version 2.0 or later).

I also recommend using the [SingletonContainer](https://github.com/yucadoo/singleton-container) to cache the resolved transformers.

First add the packages.

``` bash
$ composer require mouf/alias-container yucadoo/singleton-container
```

Then compose the new document manager using the AliasContainer.

``` php
<?php

use App\ElasticSearcher\EloquentDocumentAdapter;
use App\ElasticSearcher\Transformers\PostElasticsearchTransformer;
use App\ElasticSearcher\Transformers\UserElasticsearchTransformer;
use ElasticSearcher\Environment;
use ElasticSearcher\ElasticSearcher;
use ElasticSearcher\Managers\DocumentsManager as WrappedDocumentManager;
use League\Fractal\Manager as FractalManager;
use Mouf\AliasContainer\AliasContainer;
use Psr\Container\ContainerInterface;
use YucaDoo\ElasticSearcher\Managers\DocumentManager;
use YucaDoo\SingletonContainer\SingletonContainer;

/**
 * Resolve old document manager. This function is shown for completeness.
 * @return WrappedDocumentManager Document manager handling raw documents.
 */
function createWrappedDocumentManager(): WrappedDocumentManager
{
    $env = new Environment(
      ['hosts' => ['localhost:9200']]
    );
    $searcher = new ElasticSearcher($env);
    return $searcher->documentsManager();
}

/**
 * Resolve new document manager.
 * @param ContainerInterface $container Container providing transformer instances.
 * @return DocumentManager Document manager handling models instead of raw documents.
 */
function createNewDocumentManager(ContainerInterface $container): DocumentManager
{
    // Wrap container with singleton container to cache resolved transformers.
    $singletonContainer = new SingletonContainer($container);
    // Map index names to transformers.
    $transformerRepository = new AliasContainer($singletonContainer, [
        'posts' => PostElasticsearchTransformer::class,
        'users' => UserElasticsearchTransformer::class,
    ]);

    // Compose document manager.
    return new DocumentManager(
        createWrappedDocumentManager(),
        new FractalManager(),
        new EloquentDocumentAdapter(),
        $transformerRepository
    );
}
```

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Testing

``` bash
$ composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for details.

## Security

If you discover any security related issues, please email hrcajuka@gmail.com instead of using the issue tracker.

## Credits

- [Hrvoje Jukic][link-author]
- [All Contributors][link-contributors]

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-version]: https://img.shields.io/packagist/v/yucadoo/elasticsearcher-fractal.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/yucadoo/elasticsearcher-fractal/master.svg?style=flat-square
[ico-scrutinizer]: https://img.shields.io/scrutinizer/coverage/g/yucadoo/elasticsearcher-fractal.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/yucadoo/elasticsearcher-fractal.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/yucadoo/elasticsearcher-fractal.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/yucadoo/elasticsearcher-fractal
[link-travis]: https://travis-ci.org/yucadoo/elasticsearcher-fractal
[link-scrutinizer]: https://scrutinizer-ci.com/g/yucadoo/elasticsearcher-fractal/code-structure
[link-code-quality]: https://scrutinizer-ci.com/g/yucadoo/elasticsearcher-fractal
[link-downloads]: https://packagist.org/packages/yucadoo/elasticsearcher-fractal
[link-author]: https://github.com/yucadoo
[link-contributors]: ../../contributors
