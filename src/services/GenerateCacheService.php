<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\events\SaveCacheEvent;
use putyourlightson\blitz\helpers\ElementQueryHelper;
use putyourlightson\blitz\helpers\ElementTypeHelper;
use putyourlightson\blitz\models\CacheOptionsModel;
use putyourlightson\blitz\models\SiteUriModel;
use putyourlightson\blitz\records\CacheRecord;
use putyourlightson\blitz\records\ElementCacheRecord;
use putyourlightson\blitz\records\ElementQueryCacheRecord;
use putyourlightson\blitz\records\ElementQueryRecord;
use putyourlightson\blitz\records\ElementQuerySourceRecord;
use yii\db\Exception;

class GenerateCacheService extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event RefreshCacheEvent
     */
    const EVENT_BEFORE_SAVE_CACHE = 'beforeSaveCache';

    /**
     * @event RefreshCacheEvent
     */
    const EVENT_AFTER_SAVE_CACHE = 'afterSaveCache';

    /**
     * @const string
     */
    const MUTEX_LOCK_NAME_ELEMENT_QUERY_RECORDS = 'blitz:elementQueryRecords';

    /**
     * @const string
     */
    const MUTEX_LOCK_NAME_CACHE_RECORDS = 'blitz:cacheRecords';

    // Properties
    // =========================================================================

    /**
     * @var CacheOptionsModel
     */
    public $options;

    /**
     * @var int[]
     */
    public $elementCaches = [];

    /**
     * @var int[]
     */
    public $elementQueryCaches = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        $this->options = new CacheOptionsModel();

        // Set default attributes from the plugin settings
        $this->options->setAttributes(Blitz::$plugin->settings->getAttributes(), false);
    }

    /**
     * Adds an element to be cached.
     *
     * @param ElementInterface $element
     */
    public function addElement(ElementInterface $element)
    {
        // Don't proceed if element caching is disabled
        if (!Blitz::$plugin->settings->cacheElements || !$this->options->cacheElements) {
            return;
        }

        // Don't proceed if not a cacheable element type
        if (!ElementTypeHelper::getIsCacheableElementType(get_class($element))) {
            return;
        }

        if (!in_array($element->getId(), $this->elementCaches)) {
            $this->elementCaches[] = $element->getId();
        }
    }

    /**
     * Adds an element query to be cached.
     *
     * @param ElementQuery $elementQuery
     */
    public function addElementQuery(ElementQuery $elementQuery)
    {
        // Don't proceed if element query caching is disabled
        if (!Blitz::$plugin->settings->cacheElementQueries || !$this->options->cacheElementQueries) {
            return;
        }

        // Don't proceed if not a cacheable element type
        if (!ElementTypeHelper::getIsCacheableElementType($elementQuery->elementType)) {
            return;
        }

        // Don't proceed if the query has fixed IDs
        if (ElementQueryHelper::hasFixedIds($elementQuery)) {
            return;
        }

        // Don't proceed if the query has a join
        if (!empty($elementQuery->join)) {
            return;
        }

        $this->saveElementQuery($elementQuery);
    }

    /**
     * Saves an element query.
     *
     * @param ElementQuery $elementQuery
     */
    public function saveElementQuery(ElementQuery $elementQuery)
    {
        $params = json_encode(ElementQueryHelper::getUniqueElementQueryParams($elementQuery));

        // Create a unique index from the element type and parameters for quicker indexing and less storage
        $index = sprintf('%u', crc32($elementQuery->elementType.$params));

        $mutex = Craft::$app->getMutex();
        $lockName = self::MUTEX_LOCK_NAME_ELEMENT_QUERY_RECORDS.':'.$index;

        if (!$mutex->acquire($lockName, Blitz::$plugin->settings->mutexTimeout)) {
            return;
        }

        // Use DB connection so we can exclude audit columns when inserting
        $db = Craft::$app->getDb();

        // Get element query record from index or create one if it does not exist
        $queryId = ElementQueryRecord::find()
            ->select('id')
            ->where(['index' => $index])
            ->scalar();

        if (!$queryId) {
            try {
                $db->createCommand()
                    ->insert(ElementQueryRecord::tableName(), [
                        'index' => $index,
                        'type' => $elementQuery->elementType,
                        'params' => $params,
                    ], false)
                    ->execute();

                $queryId = $db->getLastInsertID();

                $this->saveElementQuerySources($elementQuery, $queryId);
            }
            catch (Exception $e) {
                Blitz::$plugin->log($e->getMessage(), [], 'error');
            }
        }

        if ($queryId && !in_array($queryId, $this->elementQueryCaches)) {
            $this->elementQueryCaches[] = $queryId;
        }

        $mutex->release($lockName);
    }

    /**
     * Saves an element query's sources.
     *
     * @param ElementQuery $elementQuery
     * @param string $queryId
     *
     * @throws Exception
     */
    public function saveElementQuerySources(ElementQuery $elementQuery, string $queryId)
    {
        $db = Craft::$app->getDb();

        $sourceIdAttribute = ElementTypeHelper::getSourceIdAttribute($elementQuery->elementType);
        $sourceIds = $sourceIdAttribute ? $elementQuery->$sourceIdAttribute : null;

        // Normalize source IDs
        $sourceIds = ElementQueryHelper::getNormalizedElementQueryIdParam($sourceIds);

        // Convert to an array
        if (!is_array($sourceIds)) {
            $sourceIds = [$sourceIds];
        }

        foreach ($sourceIds as $sourceId) {
            // Stop if a string is encountered
            if (is_string($sourceId)) {
                break;
            }

            $db->createCommand()
                ->insert(ElementQuerySourceRecord::tableName(), [
                    'sourceId' => $sourceId,
                    'queryId' => $queryId,
                ], false)
                ->execute();
        }
    }

    /**
     * Saves the cache for a site URI.
     *
     * @param string $output
     * @param SiteUriModel $siteUri
     */
    public function save(string $output, SiteUriModel $siteUri)
    {
        if (!$this->options->cachingEnabled) {
            return;
        }

        // Don't cache if the output contains any transform generation URLs
        // https://github.com/putyourlightson/craft-blitz/issues/125
        if (StringHelper::contains(stripslashes($output), 'assets/generate-transform')) {
            Blitz::$plugin->debug('Page not cached because it contains transform generation URLs.', [], $siteUri->getUrl());

            return;
        }

        $mutex = Craft::$app->getMutex();
        $lockName = self::MUTEX_LOCK_NAME_CACHE_RECORDS;

        if (!$mutex->acquire($lockName, Blitz::$plugin->settings->mutexTimeout)) {
            Blitz::$plugin->debug('Page not cached because a `{lockName}` mutex could not be acquired.', [
                'lockName' => $lockName,
            ], $siteUri->getUrl());

            return;
        }

        // Use DB connection so we can batch insert and exclude audit columns
        $db = Craft::$app->getDb();

        $values = $siteUri->toArray();

        // Delete cache records so we get a fresh cache
        CacheRecord::deleteAll($values);

        if (!empty($this->options->expiryDate)) {
            $values = array_merge($values, [
                'expiryDate' => Db::prepareDateForDb($this->options->expiryDate),
            ]);
        }

        $db->createCommand()
            ->insert(CacheRecord::tableName(), $values, false)
            ->execute();

        $cacheId = (int)$db->getLastInsertID();

        // Add element caches to database
        $values = [];

        foreach ($this->elementCaches as $elementId) {
            $values[] = [$cacheId, $elementId];
        }

        $db->createCommand()
            ->batchInsert(ElementCacheRecord::tableName(),
                ['cacheId', 'elementId'],
                $values,
                false)
            ->execute();

        // Add element query caches to database
        $values = [];

        foreach ($this->elementQueryCaches as $queryId) {
            $values[] = [$cacheId, $queryId];
        }

        $db->createCommand()
            ->batchInsert(ElementQueryCacheRecord::tableName(),
                ['cacheId', 'queryId'],
                $values,
                false)
            ->execute();

        // Add tag caches to database
        if (!empty($this->options->tags)) {
            Blitz::$plugin->cacheTags->saveTags($this->options->tags, $cacheId);
        }

        if (Blitz::$plugin->settings->outputComments) {
            // Append timestamp
            $output .= '<!-- Cached by Blitz on '.date('c').' -->';
        }

        $this->saveOutput($output, $siteUri);

        $mutex->release($lockName);
    }

    /**
     * Saves the output for a site URI.
     *
     * @param string $output
     * @param SiteUriModel $siteUri
     */
    public function saveOutput(string $output, SiteUriModel $siteUri)
    {
        $event = new SaveCacheEvent([
            'output' => $output,
            'siteUri' => $siteUri,
        ]);
        $this->trigger(self::EVENT_BEFORE_SAVE_CACHE, $event);

        if (!$event->isValid) {
            Blitz::$plugin->debug('Page not cached because the `{event}` event is not valid.', [
                'event' => self::EVENT_BEFORE_SAVE_CACHE,
            ], $siteUri->getUrl());

            return;
        }

        Blitz::$plugin->cacheStorage->save($event->output, $event->siteUri);

        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_CACHE)) {
            $this->trigger(self::EVENT_AFTER_SAVE_CACHE, $event);
        }
    }
}
