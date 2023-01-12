<?php
/**
 * @copyright Copyright (c) SeriesEight
 */

namespace serieseight\blitzbunnycdn;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\App;
use craft\web\View;
use craft\i18n\PhpMessageSource;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use putyourlightson\blitz\drivers\purgers\BaseCachePurger;
use putyourlightson\blitz\events\RefreshCacheEvent;
use putyourlightson\blitz\helpers\SiteUriHelper;
use yii\base\Event;

/**
 * @property-read null|string $settingsHtml
 */
class BunnyCdnPurger extends BaseCachePurger
{
	public const API_ENDPOINT = 'https://api.bunny.net/';

	/**
	 * @var string
	 */
	public string $accessKey = '';

	/**
	 * @var string
	 */
	public string $zoneIds = '';

	/**
	 * @inheritdoc
	 */
	public static function displayName(): string
	{
		return Craft::t('serieseight', 'Bunny CDN Purger');
	}

	/**
	 * @inheritdoc
	 */
	public function init(): void
	{
		Craft::$app->i18n->translations['serieseight'] = [
			'class' => PhpMessageSource::class,
			'sourceLanguage' => 'en',
			'basePath' => __DIR__ . '/translations',
			'allowOverrides' => true,
		];

		Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
			function(RegisterTemplateRootsEvent $event) {
				$event->roots['blitz-bunnycdn'] = __DIR__ . '/templates/';
			}
		);
	}

	/**
	 * @inheritdoc
	 */
	public function behaviors(): array
	{
		$behaviors = parent::behaviors();
		$behaviors['parser'] = [
			'class' => EnvAttributeParserBehavior::class,
			'attributes' => [
				'accessKey',
				'zoneIds',
			],
		];

		return $behaviors;
	}

	/**
	 * @inheritdoc
	 */
	public function attributeLabels(): array
	{
		return [
			'accessKey' => Craft::t('serieseight', 'Access Key'),
			'zoneIds' => Craft::t('serieseight', 'Zone IDs'),
		];
	}

	/**
	 * @inheritdoc
	 */
	public function rules(): array
	{
		return [
			[['accessKey', 'zoneIds'], 'required'],
		];
	}

	/**
	 * @inheritdoc
	 */
	public function purgeUris(array $siteUris, callable $setProgressHandler = null): void
	{
		$event = new RefreshCacheEvent(['siteUris' => $siteUris]);
		$this->trigger(self::EVENT_BEFORE_PURGE_CACHE, $event);

		if (!$event->isValid) {
			return;
		}

		$this->purgeUrisWithProgress($siteUris, $setProgressHandler);

		if ($this->hasEventHandlers(self::EVENT_AFTER_PURGE_CACHE)) {
			$this->trigger(self::EVENT_AFTER_PURGE_CACHE, $event);
		}
	}

	/**
	 * @inheritdoc
	 */
	public function purgeUrisWithProgress(array $siteUris, callable $setProgressHandler = null): void
	{
		$count = 0;
		$total = count($siteUris);
		$label = 'Purging {total} pages.';

		if (is_callable($setProgressHandler)) {
			$progressLabel = Craft::t('serieseight', $label, ['total' => $total]);
			call_user_func($setProgressHandler, $count, $total, $progressLabel);
		}

		$urls = SiteUriHelper::getUrlsFromSiteUris($siteUris);
		if (count($urls)) {
			$this->_sendRequest('purge', [
				'urls' => SiteUriHelper::getUrlsFromSiteUris($siteUris),
			]);
		}

		$count = $total;

		if (is_callable($setProgressHandler)) {
			$progressLabel = Craft::t('serieseight', $label, ['total' => $total]);
			call_user_func($setProgressHandler, $count, $total, $progressLabel);
		}
	}

	/**
	 * @inheritdoc
	 */
	public function purgeAll(callable $setProgressHandler = null, bool $queue = true): void
	{
		$event = new RefreshCacheEvent();
		$this->trigger(self::EVENT_BEFORE_PURGE_ALL_CACHE, $event);

		if (!$event->isValid) {
			return;
		}

		foreach ($this->_getZoneIds() as $zoneId) {
			$this->_sendRequest('purge-zone', [
				'zoneId' => $zoneId
			]);
		}

		if ($this->hasEventHandlers(self::EVENT_AFTER_PURGE_ALL_CACHE)) {
			$this->trigger(self::EVENT_AFTER_PURGE_ALL_CACHE, $event);
		}
	}

	/**
	 * @inheritdoc
	 */
	public function test(): bool
	{
		foreach ($this->_getZoneIds() as $zoneId) {
			$response = $this->_sendRequest('test', [
				'zoneId' => $zoneId
			]);

			if (!$response) {
				return false;
			}

			if ($response->getStatusCode() !== 200) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function getSettingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate('blitz-bunnycdn/settings', [
			'purger' => $this,
		]);
	}

	/**
	 * takes string seperated list of Zone IDs and formats as an array, parses the env for each item and removes empties
	 */
	private function _getZoneIds() {
		return array_filter(
			array_map(
				function($zoneId) {
					return App::parseEnv($zoneId);
				},
				explode(',', $this->zoneIds)
			),
			function($zoneId) {
				return !!$zoneId;
			});
	}

	/**
	 * Sends a request to the API.
	 */
	private function _sendRequest(string $action = '', array $params = []): ?ResponseInterface
	{
		$response = null;

		$client = Craft::createGuzzleClient([
			'base_uri' => self::API_ENDPOINT,
			'headers' => [
				'Accept' => 'application/json',
				'AccessKey' => App::parseEnv($this->accessKey),
				'Content-Type' => 'application/json',
			],
		]);

		$method = 'GET';
		$uri = '';
		switch ($action) {
			case 'purge':
				$urls = implode(',', $params['urls']);
				$uri = 'purge?url=' . $urls;
				break;
			case 'purge-zone':
				$method = 'POST';
				$uri = 'pullzone/' . $params['zoneId'] . '/purgeCache';
				break;
			case 'test':
				$uri = '/pullzone/' . $params['zoneId'];
				break;
		}

		try {
			$response = $client->request($method, $uri);
		}
		catch (BadResponseException|GuzzleException $e) {
		}

		return $response;
	}
}
