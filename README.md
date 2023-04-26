# Wwwision.Markwhen

Projection that can render the state of the Event Sourced Content Repository into [markwhen](https://markwhen.com) syntax

> **Warning**
> This package mostly serves as demonstration on how to write a simple [Event Sourced Content Repository](https://docs.neos.io/guide/contributing-to-neos/event-sourced-content-repository) projection
> It's probably not very useful in its current form, but feel free to copy and adjust it to your needs!

## Usage

### Installation

install via [composer](https://getcomposer.org):

```shell
composer require wwwision/markwhen
```

> **Note**
> At the time of writing, a couple of required packages are not yet available on packagist
> You can download those from GitHub to your distribution folder:
> https://github.com/neos/neos-development-collection/tree/9.0/Neos.ContentRepository.Core
> https://github.com/neos/neos-development-collection/tree/9.0/Neos.ContentRepositoryRegistry
> https://github.com/neos/neos-development-collection/tree/9.0/Neos.ContentGraph.DoctrineDbalAdapter
> https://github.com/neos/neos-development-collection/tree/9.0/Neos.ContentGraph.PostgreSQLAdapter
> and install everything via `composer require wwwision/markwhen neos/contentrepositoryregistry:@dev neos/contentgraph-doctrinedbaladapter:@dev neos/contentgraph-postgresqladapter:@dev`

Afterwards you can replay the projection to built up its initial state:

```shell
./flow cr:replay markwhen
```

Once the state is persisted, you can call

```shell
./flow markwhen:render
```

to turn it into markwhen syntax.
This will output the result to the console output directly, but you can store it into a file with:

```shell
./flow markwhen:render > out.mw
```

With the help of the [Markwhen CLI](https://docs.markwhen.com/cli.html) tool you can turn that into a timeline or calendar:

```shell
mw out.mv -d timeline.html 
mw out.mv -d calendar.html
```

Or pipe the output of the render command to `mw` directly: 

```shell
./flow markwhen:render | mw /dev/stdin -d timeline.html
```

## Files in this package

* [MarkwhenCommandController.php](Classes/Command/MarkwhenCommandController.php): Flow CLI controller that provides the `markwhen:*` commands
* [MarkwhenProjection.php](Classes/MarkwhenProjection.php): The actual Content Repository projection
* [MarkwhenProjectionFactory.php](Classes/MarkwhenProjectionFactory.php): Simple factory for the projection that is configured with the Content Repository Registry
* [MarkwhenProjectionState.php](Classes/MarkwhenProjectionState.php): PHP class that holds the state of the projection and can be turned into (and restored from) JSON
* [Settings.yaml](Configuration/Settings.yaml): Configuration for this package, registering the projection with the "default" Content Repository

## Different Content Repository

This package registers the `MarkwhenProjection` with the `default` content repository.
To register it to a different instance, you can adjust the settings accordingly:

```yaml
Neos:
  ContentRepositoryRegistry:
    contentRepositories:
      'acme':
        projections:
          'Wwwision.Markwhen:Markwhen':
            factoryObjectName: Wwwision\Markwhen\MarkwhenProjectionFactory

```

Or you register it with a Content Repository **preset** so that it can be used in multiple CRs without additional configuration (see [docs.neos.io](https://docs.neos.io/guide/manual/content-repository/configuration):

```yaml
Neos:
  ContentRepositoryRegistry:
    presets:
      'somePreset':
        projections:
          'Wwwision.Markwhen:Markwhen':
            factoryObjectName: Wwwision\Markwhen\MarkwhenProjectionFactory
```

Don't forget to specify the Content Repository ID for the commands:

```shell
./flow cr:replay markwhen --content-repository acme
./flow markwhen:render --content-repository acme
```
