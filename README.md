# Bunny CDN KeyCDN Purger for Craft CMS

The Bunny CDN Purger allows the [Blitz](https://putyourlightson.com/plugins/blitz) plugin for [Craft CMS](https://craftcms.com/) to intelligently purge cached pages.

## Usage

Install the purger using composer. Currently this requires adding the Git repo to your `composer.json`:

```json
{
	"require": {
		"serieseight/craft-blitz-bunnycdn": "dev-main"
	},
	"repositories":[
		{
			"type": "vcs",
			"url": "git@github.com:serieseight/craft-blitz-bunnycdn.git"
		}
	]
}

```

Then add the class to the `cachePurgerTypes` config setting in `config/blitz.php`.

```php
// The purger type classes to add to the plugin’s default purger types.
'cachePurgerTypes' => [
	'serieseight\blitzbunnycdn\BunnyCdnPurger',
],
```

You can then select the purger and settings either in the control panel or in `config/blitz.php`.

```php
// The purger type to use.
'cachePurgerType' => 'serieseight\blitzbunnycdn\BunnyCdnPurger',

// The purger settings.
'cachePurgerSettings' => [
	'accessKey' => '$BUNNY_ACCESS_KEY',
	'zoneIds'   => '$BUNNY_ZONE_ID',
],
```

## Documentation

Read the documentation at [putyourlightson.com/plugins/blitz](https://putyourlightson.com/plugins/blitz#reverse-proxy-purgers).

Created by [Series Eight](https://serieseight.com/).
