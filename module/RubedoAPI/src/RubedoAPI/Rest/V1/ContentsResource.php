<?php
/**
 * Rubedo -- ECM solution
 * Copyright (c) 2014, WebTales (http://www.webtales.fr/).
 * All rights reserved.
 * licensing@webtales.fr
 *
 * Open Source License
 * ------------------------------------------------------------------------------------------
 * Rubedo is licensed under the terms of the Open Source GPL 3.0 license.
 *
 * @category   Rubedo
 * @package    Rubedo
 * @copyright  Copyright (c) 2012-2014 WebTales (http://www.webtales.fr)
 * @license    http://www.gnu.org/licenses/gpl.html Open Source GPL 3.0 license
 */

namespace RubedoAPI\Rest\V1;

use Rubedo\Collection\AbstractLocalizableCollection;
use Rubedo\Services\Manager;
use RubedoAPI\Entities\API\Definition\FilterDefinitionEntity;
use RubedoAPI\Entities\API\Definition\VerbDefinitionEntity;
use RubedoAPI\Exceptions\APIAuthException;
use RubedoAPI\Exceptions\APIEntityException;
use RubedoAPI\Exceptions\APIRequestException;
use WebTales\MongoFilters\Filter;
use Rubedo\Content\Context;

/**
 * Class ContentsResource
 * @package RubedoAPI\Rest\V1
 */
class ContentsResource extends AbstractResource
{
    /**
     * Cache lifetime for api cache (only for get and getEntity)
     * @var int
     */
    public $cacheLifeTime=60;

    /**
     * @var array
     */
    protected $toExtractFromFields = array('text');
    /**
     * @var array
     */
    protected $otherLocalizableFields = array('text', 'summary');

    /**
     * @var array
     */
    protected $returnedEntityFields = array(
        'id',
        'text',
        'version',
        'createUser',
        'lastUpdateUser',
        'fields',
        'taxonomy',
        'status',
        'pageId',
        'maskId',
        'locale',
        'readOnly',
        'createTime',
        'lastUpdateTime',
        'isProduct',
        'productProperties'
    );

    /**
     * { @inheritdoc }
     */
    public function __construct()
    {
        parent::__construct();
        $this->define();
    }

    /**
     * Get to contents
     *
     * @param $params
     * @return array
     * @throws \RubedoAPI\Exceptions\APIEntityException
     */
    public function getAction($params)
    {

        if (isset($params['useDraftMode'])){
            Context::setIsDraft(true);
        }
        if (isset($params['simulatedTime'])){
            Manager::getService('CurrentTime')->setSimulatedTime($params['simulatedTime']);
        }
        $queryId = &$params['queryId'];
        $this->getQueriesCollection()->setCurrentPage((string)$params['pageId']);
        $filters = $this->getQueriesCollection()->getFilterArrayById($queryId);
        $ismagic = false;

        if (isset($params['date']) && isset($params['dateFieldName'])) {
            if (isset($params['endDate'])) {
                $timestamp = $params['date'];
                $nextMonthTimeStamp = $params['endDate'];
            } else {
                $dateArray = getdate($params['date']);
                $mounth = $dateArray['mon'];
                $year = $dateArray['year'];
                $date = new \DateTime();
                $date->setDate($year, $mounth, 1);
                $date->setTime(0, 0, 0);
                $timestamp = (string)$date->getTimestamp();
                if ($mounth < 12) {
                    $date->setDate($year, $mounth + 1, 1);
                } else {
                    $date->setDate($year + 1, 1, 1);
                }
                $nextMonthTimeStamp = (string)$date->getTimestamp();
            }

            $eventStartInCurrentlyMonth = Filter::factory('And')
                ->addFilter(Filter::factory('OperatorTovalue')->setName('fields.' . $params['dateFieldName'])
                    ->setOperator('$gte')
                    ->setValue($timestamp))
                ->addFilter(Filter::factory('OperatorTovalue')->setName('fields.' . $params['dateFieldName'])
                    ->setOperator('$lt')
                    ->setValue($nextMonthTimeStamp));

            if (isset($params['endDateFieldName'])) {
                $eventStartingBeforeCurrenltyMonth = Filter::factory('And')
                    ->addFilter(Filter::factory('OperatorTovalue')
                        ->setName('fields.' . $params['dateFieldName'])
                        ->setOperator('$lt')
                        ->setValue($timestamp))
                    ->addFilter(Filter::factory('OperatorTovalue')
                        ->setName('fields.' . $params['endDateFieldName'])
                        ->setOperator('$gte')
                        ->setValue($timestamp));

                $eventsInMonth = Filter::factory('Or')
                    ->addFilter($eventStartingBeforeCurrenltyMonth)
                    ->addFilter($eventStartInCurrentlyMonth);
            } else {
                $eventsInMonth = $eventStartInCurrentlyMonth;
            }
            $filters['filter']->addFilter($eventsInMonth);
        }


        if ($filters === false) {
            throw new APIEntityException('Query not found', 404);
        }

        $queryType = $filters['queryType'];
        $query = $this->getQueriesCollection()->getQueryById($queryId);


        $filters['filter']->addFilter(
            $this->productFilter()
        );

        if (!empty($params['requiredFields']) && is_array($params['requiredFields'])) {
            foreach ($params['requiredFields'] as $requiredField) {
                $filters['filter']->addFilter(
                    Filter::factory('OperatorToValue')->setName('fields.' . $requiredField)->setOperator('$exists')->setValue(true)
                );
                $filters['filter']->addFilter(
                    Filter::factory('OperatorToValue')->setName('fields.' . $requiredField)->setOperator('$ne')->setValue("")
                );
            }
        }

        if ($queryType === 'manual' && $query != false && isset($query['query']) && is_array($query['query'])) {
            $contentOrder = $query['query'];
            $keyOrder = array();
            $contentArray = array();

            // getList
            $unorderedContentArray = $this->getContentList($filters, $this->setPaginationValues($params));

            foreach ($contentOrder as $value) {
                foreach ($unorderedContentArray['data'] as $subKey => $subValue) {
                    if ($value === $subValue['id']) {
                        $keyOrder[] = $subKey;
                    }
                }
            }

            foreach ($keyOrder as $value) {
                $contentArray['data'][] = $unorderedContentArray['data'][$value];
            }

            $nbItems = $unorderedContentArray['count'];
        } else {
            if (!empty($params['fingerprint'])) {
                $this->getSessionService()->set('fingerprint', $params['fingerprint']);
            }
            if (isset($params['ismagic']) && $params['ismagic'] == "true") {
                $ismagic = true;
            }
            $contentArray = $this->getContentList($filters, $this->setPaginationValues($params), $ismagic);
            $nbItems = $contentArray['count'];
        }
        return [
            'success' => true,
            'contents' => $this->outputContentsMask($contentArray['data'], $params, $query),
            'count' => $nbItems,
            'queryType' => $query['type'],
            'usedContentTypes' => isset($query["query"]["contentTypes"]) ? $query["query"]["contentTypes"] : array()
        ];
    }

    /**
     * Add the new content
     *
     * @param $params
     * @throws \RubedoAPI\Exceptions\APIAuthException
     * @throws \RubedoAPI\Exceptions\APIEntityException
     * @return array
     */
    public function postAction($params)
    {
        $data = &$params['content'];
        if (empty($data['typeId'])) {
            throw new APIEntityException('typeId data is missing.', 400);
        }

        $type = $this->getContentTypesCollection()->findById($data['typeId']);
        if (empty($type)) {
            throw new APIEntityException('ContentType not found.', 404);
        }

        foreach ($data['fields'] as $fieldName => $fieldValue) {
            if (in_array($fieldName, $this->toExtractFromFields)) {
                $data[$fieldName] = $fieldValue;
            }
        }

        if (!isset($data['i18n'])) {
            $data['i18n'] = array();
        }

        if (!isset($data['i18n'][$params['lang']->getLocale()])) {
            $data['i18n'][$params['lang']->getLocale()] = array();
        }

        $data['i18n'][$params['lang']->getLocale()]['fields'] = $this->localizableFields($type, $data['fields']);

        $data['fields'] = $this->filterFields($type, $data['fields']);

        if (!isset($data['status'])) {
            $data['status'] = 'published';
        }

        if (!isset($data['target'])) {
            $data['target'] = array();
        }

        if (!isset($data['nativeLanguage'])) {
            $data['nativeLanguage'] = $params['lang']->getLocale();
        }

        if (!$this->getAclService()->hasAccess('write.ui.contents.' . $data['status'])) {
            throw new APIAuthException('You have no suffisants rights', 403);
        }

        return $this->getContentsCollection()->create($data, array(), false);
    }

    public function patchAction($params)
    {
        $contents=$params['contents'];
        $versions=array();
        $success = false;
        foreach($contents as $content){
            $params['content'] = $content;
            $updateContent=$this->patchEntityAction($content['id'],$params);
            $versions[]=(isset($updateContent['version']) && $updateContent['version'])? $updateContent['version'] : false;
            $success = $updateContent['success'] ? true : $success;
        }
        return[
            'success' => $success,
            'versions' => $versions
        ];
    }

    /**
     * Remove fields if not in content type
     *
     * @param $type
     * @param $fields
     */
    protected function filterFields($type, $fields)
    {
        $existingFields = array();
        foreach ($type['fields'] as $field) {
            if (!($field['config']['localizable'] || in_array($field['config']['name'], $this->otherLocalizableFields))) {
                $existingFields[] = $field['config']['name'];
            }
        }
        foreach ($fields as $key => $value) {
            unset($value); //unused
            if (!in_array($key, $existingFields)) {
                unset ($fields[$key]);
            }
        }
        return $fields;
    }

    /**
     * Return localizable fields if not in content type
     *
     * @param $type
     * @param $fields
     */
    protected function localizableFields($type, $fields)
    {
        $existingFields = array();
        foreach ($type['fields'] as $field) {
            if ($field['config']['localizable']) {
                $existingFields[] = $field['config']['name'];
            }
        }
        foreach ($fields as $key => $value) {
            unset($value); //unused
            if (!(in_array($key, $existingFields) || in_array($key, $this->otherLocalizableFields))) {
                unset ($fields[$key]);
            }
        }
        return $fields;
    }

    /**
     * Filter contents
     *
     * @param $contents
     * @param $params
     * @return mixed
     */
    protected function outputContentsMask($contents, $params, $query)
    {
        $fields = isset($params['fields']) ? $params['fields'] : array('text', 'summary', 'image');
        $queryReturnedFields = !empty($query["returnedFields"]) && is_array($query["returnedFields"]) ? $query["returnedFields"] : array();
        $fields = array_merge($fields, $queryReturnedFields);
        $urlService = $this->getUrlAPIService();
        $page = $this->getPagesCollection()->findById($params['pageId']);
        $site = $this->getSitesCollection()->findById($params['siteId']);
        $mask = array('isProduct', 'i18n', 'pageId', 'blockId', 'maskId');
        foreach ($contents as &$content) {
            $content['fields'] = array_intersect_key($content['fields'], array_flip($fields));
            $content['detailPageUrl'] = $urlService->displayUrlApi($content, 'default', $site,
                $page, $params['lang']->getLocale(), isset($params['detailPageId']) ? (string)$params['detailPageId'] : null);
            $content = array_diff_key($content, array_flip($mask));
        }
        return $contents;
    }

    /**
     * Get content list
     *
     * @param $filters
     * @param $pageData
     * @param bool $ismagic
     * @return array
     * @throws \RubedoAPI\Exceptions\APIEntityException
     */
    protected function getContentList($filters, $pageData, $ismagic = false)
    {
        $filters["sort"] = isset($filters["sort"]) ? $filters["sort"] : array();
        $contentArray = $this->getContentsCollection()->getOnlineList($filters["filter"], $filters["sort"], $pageData['start'], $pageData['limit'], $ismagic);
        $contentArray['page'] = $pageData;
        if ($contentArray['count'] < $pageData['start']) {
            throw new APIEntityException('There is only ' . $contentArray['count'] . ' contents. Start parameter must be inferior of this value', 404);
        }
        return $contentArray;
    }

    /**
     * Set pagination value
     *
     * @param $params
     * @return mixed
     * @throws \RubedoAPI\Exceptions\APIEntityException
     */
    protected function setPaginationValues($params)
    {
        $defaultLimit = isset($params['limit']) ? $params['limit'] : 6;
        $defaultStart = isset($params['start']) ? $params['start'] : 0;
        if ($defaultStart < 0) {
            throw new APIEntityException('Start paramater must be >= 0', 404);
        }
        if ($defaultLimit < 1) {
            throw new APIEntityException('Limit paramater must be >= 1', 404);
        }
        $pageData['start'] = $defaultStart;
        $pageData['limit'] = $defaultLimit;
        return $pageData;
    }

    /**
     * Patch a content
     *
     * @param $id
     * @param $params
     * @return array
     * @throws \RubedoAPI\Exceptions\APIAuthException
     * @throws \RubedoAPI\Exceptions\APIEntityException
     */
    public function patchEntityAction($id, $params)
    {
        AbstractLocalizableCollection::setIncludeI18n(true);
        $content = $this->getContentsCollection()->findById($id, false, false);
        if (empty($content)) {
            throw new APIEntityException('Content not found', 404);
        }
        $data = &$params['content'];
        $type = $this->getContentTypesCollection()->findById(empty($data['typeId']) ? $content['typeId'] : $data['typeId']);
        if (empty($type)) {
            throw new APIEntityException('ContentType not found.', 404);
        }

        if (isset($data['fields'])) {
            if ($content['nativeLanguage'] === $params['lang']->getLocale()) {
                foreach ($data['fields'] as $fieldName => $fieldValue) {
                    if (in_array($fieldName, $this->toExtractFromFields)) {
                        $data[$fieldName] = $fieldValue;
                    }
                }
            }
            if (!isset($data['i18n'])) {
                $data['i18n'] = array();
            }
            if (!isset($data['i18n'][$params['lang']->getLocale()])) {
                $data['i18n'][$params['lang']->getLocale()] = array();
            }
            $data['i18n'][$params['lang']->getLocale()]['fields'] = $this->localizableFields($type, $data['fields']);
            $data['fields'] = $this->filterFields($type, $data['fields']);
        }

        if (isset($data['status']) && !$this->getAclService()->hasAccess('write.ui.contents.' . $data['status'])) {
            throw new APIAuthException('You have no suffisants rights', 403);
        }

        $content = array_replace_recursive($content, $data);
        $update = $this->getContentsCollection()->update($content, array(), false);
        return [
            'success' => $update['success'],
            'version' => isset($update['data'],$update['data']['version'])?$update['data']['version'] : false,
        ];
    }

    /**
     * Get to contents/{id}
     *
     * @param $id
     * @param $params
     * @return array
     * @throws \RubedoAPI\Exceptions\APIEntityException
     * @throws \RubedoAPI\Exceptions\APIRequestException
     */
    public function getEntityAction($id, $params)
    {
        $content = $this->getContentsCollection()->findById($id, true, false);
        if (empty($content)) {
            throw new APIEntityException('Content not found', 404);
        }

        $contentType = $this->getContentTypesCollection()->findById($content['typeId'], true, false);
        if (empty($contentType)) {
            throw new APIEntityException('ContentType not found', 404);
        }

        if (isset($params['fingerprint'])) {
            $currentTime = $this->getCurrentTimeService()->getCurrentTime();
            //get user fingerprint
            $this->getContentViewLogCollection()->log($content['id'], $content['locale'], $params['fingerprint'], $currentTime);
            //rebuild user recommendations if necessary
            $emptyFilter = Filter::factory();
            $oldestView = $this->getContentViewLogCollection()->findOne($emptyFilter);
            if ($oldestView) {
                $timeSinceLastRun = $currentTime - $oldestView['timestamp'];
                if ($timeSinceLastRun > 60) {
                    $curlUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/queue?service=UserRecommendations&class=build';
                    $curly = curl_init();
                    curl_setopt($curly, CURLOPT_URL, $curlUrl);
                    curl_setopt($curly, CURLOPT_FOLLOWLOCATION, true);  // Follow the redirects (needed for mod_rewrite)
                    curl_setopt($curly, CURLOPT_HEADER, false);         // Don't retrieve headers
                    curl_setopt($curly, CURLOPT_NOBODY, true);          // Don't retrieve the body
                    curl_setopt($curly, CURLOPT_RETURNTRANSFER, true);  // Return from curl_exec rather than echoing
                    curl_setopt($curly, CURLOPT_FRESH_CONNECT, true);   // Always ensure the connection is fresh
                    // Timeout super fast once connected, so it goes into async.
                    curl_setopt($curly, CURLOPT_TIMEOUT, 1);
                    curl_exec($curly);
                }
            }
        }
        if (isset($content['isProduct'])&&$content['isProduct']&&isset($content["productProperties"]["variations"])&&is_array($content["productProperties"]["variations"])){
            $userTypeId = "*";
            $country = "*";
            $region = "*";
            $postalCode = "*";
            $currentUser = $this->getCurrentUserAPIService()->getCurrentUser();
            if ($currentUser) {
                $userTypeId = $currentUser['typeId'];
                if (isset($currentUser['shippingAddress']['country']) && !empty($currentUser['shippingAddress']['country'])) {
                    $country = $currentUser['shippingAddress']['country'];
                }
                if (isset($currentUser['shippingAddress']['regionState']) && !empty($currentUser['shippingAddress']['regionState'])) {
                    $region = $currentUser['shippingAddress']['regionState'];
                }
                if (isset($currentUser['shippingAddress']['postCode']) && !empty($currentUser['shippingAddress']['postCode'])) {
                    $postalCode = $currentUser['shippingAddress']['postCode'];
                }
            }
            foreach ($content["productProperties"]["variations"] as &$variation){
                $variation["price"]=$this->getTaxesCollection()->getTaxValue($content['typeId'], $userTypeId, $country, $region, $postalCode, $variation["price"]);
                if (isset($variation["specialOffers"])&&is_array($variation["specialOffers"])){
                    foreach ($variation["specialOffers"] as &$specialOffer){
                        $specialOffer["price"]=$this->getTaxesCollection()->getTaxValue($content['typeId'], $userTypeId, $country, $region, $postalCode, $specialOffer["price"]);
                    }
                }
            }
        }

        $content = array_intersect_key(
            $content,
            array_flip(
                $this->returnedEntityFields
            )
        );

        if (isset($params['fields'])) {
            if (!is_array($params['fields']))
                throw new APIRequestException('"fields" must be an array', 400);
            $content['fields'] = array_intersect_key($content['fields'], array_flip($params['fields']));
        }

        if (isset($params['pageId']) && isset($params['siteId'])) {
            $page = $this->getPagesCollection()->findById($params['pageId']);
            $site = $this->getSitesCollection()->findById($params['siteId']);
            $content['canonicalUrl'] = $this->getUrlAPIService()->displayUrlApi($content, 'canonical', $site,
                $page, $params['lang']->getLocale(), null);
        }

        $content['type'] = array_intersect_key(
            $contentType,
            array_flip(
                array(
                    'id',
                    'code',
                    'activateDisqus',
                    'layouts',
                    'fields',
                    'locale',
                    'version',
                    'workflow',
                    'readOnly',
                    'manageStock'
                )
            )
        );

        //remove inactive layouts

        if (isset($content['type']['layouts'])&&is_array($content['type']['layouts'])) {
            foreach ($content['type']['layouts'] as $key => $value) {
                if (!$value['active']) {
                    unset ($content['type']['layouts'][$key]);
                }
            }
        }

        //if site is set filter layouts

        if (isset($content['type']['layouts']) &&is_array($content['type']['layouts']) && isset($params['siteId'])) {
            foreach ($content['type']['layouts'] as $key => $value) {
                if ($value['site'] != $params['siteId']) {
                    unset ($content['type']['layouts'][$key]);
                }
            }
        }

        return [
            'success' => true,
            'content' => $content,
        ];
    }

    /**
     * Define the resource
     */
    protected function define()
    {
        $this
            ->definition
            ->setName('Contents')
            ->setDescription('Deal with contents')
            ->editVerb('get', function (VerbDefinitionEntity &$definition) {
                $this->defineGet($definition);
            })
            ->editVerb('post', function (VerbDefinitionEntity &$definition) {
                $this->definePost($definition);
            })
            ->editVerb('patch', function (VerbDefinitionEntity &$definition) {
                $this->definePatch($definition);
            });
        $this
            ->entityDefinition
            ->setName('Content')
            ->setDescription('Works on single content')
            ->editVerb('get', function (VerbDefinitionEntity &$definition) {
                $this->defineEntityGet($definition);
            })
            ->editVerb('patch', function (VerbDefinitionEntity &$definition) {
                $this->defineEntityPatch($definition);
            });
    }

    /**
     * Define get action
     *
     * @param VerbDefinitionEntity $definition
     */
    protected function defineGet(VerbDefinitionEntity &$definition)
    {
        $definition
            ->setDescription('Get a list of contents')
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('queryId')
                    ->setRequired()
                    ->setDescription('Id of the query')
                    ->setFilter('\\MongoId')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('siteId')
                    ->setRequired()
                    ->setDescription('Id of the site')
                    ->setFilter('\\MongoId')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('pageId')
                    ->setRequired()
                    ->setDescription('Id of the page')
                    ->setFilter('\\MongoId')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('detailPageId')
                    ->setDescription('Id of the linked page')
                    ->setFilter('\\MongoId')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('fields')
                    ->setDescription('Mask of fields')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('dateFieldName')
                    ->setDescription('Name of the date field for the query')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('endDateFieldName')
                    ->setDescription('Name of the endDate field for the query')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('useDraftMode')
                    ->setDescription('Set to true to preview draft contents')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('simulatedTime')
                    ->setDescription('Simulate time to view future or past contents')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('date')
                    ->setDescription('Date filter for the query')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('endDate')
                    ->setDescription('endDate filter for the query')
            )->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('requiredFields')
                    ->setDescription('Array of required fields used to further refine the results')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('fingerprint')
                    ->setDescription('Fingerprint')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('start')
                    ->setDescription('Item\'s index number to start')
                    ->setFilter('int')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('limit')
                    ->setDescription('How much contents to return')
                    ->setFilter('int')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('ismagic')
                    ->setDescription('Enable magic queries')
            )
            ->addOutputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('contents')
                    ->setDescription('List of contents')
            )
            ->addOutputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('count')
                    ->setDescription('Number of all contents')
            )->addOutputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('queryType')
                    ->setDescription('Query used by content list')
            )->addOutputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('usedContentTypes')
                    ->setDescription('Query used by content list')
            );
    }

    /**
     * Define post
     *
     * @param VerbDefinitionEntity $definition
     */
    protected function definePost(VerbDefinitionEntity &$definition)
    {
        $definition
            ->setDescription('Post a new content')
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setDescription('The content to post')
                    ->setKey('content')
                    ->setMultivalued()
            )
            ->identityRequired();
    }

    protected function definePatch(VerbDefinitionEntity &$definition)
    {
        $definition->setDescription('Patch a list of contents')
            ->addInputFilter(
                (new FilterDefinitionEntity())
                ->setDescription('Contents to patch')
                ->setKey('contents')
            )
            ->identityRequired()
            ->addOutputFilter(
                (new FilterDefinitionEntity())
                    ->setDescription('List of new versions of contents send')
                    ->setKey('versions')
                    ->setRequired()
            );
    }

    /**
     * Define get entity
     *
     * @param VerbDefinitionEntity $definition
     */
    protected function defineEntityGet(VerbDefinitionEntity &$definition)
    {
        $definition
            ->setDescription('Get a content')
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setDescription('Fields to return')
                    ->setKey('fields')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('siteId')
                    ->setDescription('Id of the site')
                    ->setFilter('\\MongoId')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('pageId')
                    ->setDescription('Id of the page')
                    ->setFilter('\\MongoId')
            )
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setKey('fingerprint')
                    ->setDescription('Fingerprint')
            )
            ->addOutputFilter(
                (new FilterDefinitionEntity())
                    ->setDescription('The content')
                    ->setKey('content')
                    ->setRequired()
            );
    }

    /**
     * Define get entity
     *
     * @param VerbDefinitionEntity $definition
     */
    protected function defineEntityPatch(VerbDefinitionEntity &$definition)
    {
        $definition
            ->setDescription('Patch a content')
            ->identityRequired()
            ->addInputFilter(
                (new FilterDefinitionEntity())
                    ->setDescription('The content')
                    ->setKey('content')
                    ->setRequired()
            )
            ->addOutputFilter(
                (new FilterDefinitionEntity())
                    ->setDescription('The content')
                    ->setKey('version')
                    ->setRequired()
            );
    }

    /**
     * Add product filter
     *
     * @return $this
     */
    protected function productFilter()
    {
        return Filter::factory('Or')
            ->addFilter(Filter::factory('OperatorToValue')->setName('isProduct')->setOperator('$exists')->setValue(false))
            ->addFilter(Filter::factory('Value')->setName('isProduct')->setValue(false));
    }
}