<?php

/**
 * Catalog.php
 *
 * PHP version 5
 *
 * @category   MFX
 * @package    Mageflow_Connect
 * @subpackage Helper
 * @author     Prototypely Ltd, Estonia <info@prototypely.com>
 * @copyright  Copyright © 2017 Prototypely Ltd, Estonia (http://prototypely.com)
 * @license MIT Copyright (c) 2017 Prototypely Ltd
 * @link       http://mageflow.com/
 */

/**
 * Mageflow_Connect_Model_Handler_Promotion_Rule_Catalog
 *
 * @category   MFX
 * @package    Mageflow_Connect
 * @subpackage Helper
 * @author     Prototypely Ltd, Estonia <info@prototypely.com>
 * @copyright  Copyright © 2017 Prototypely Ltd, Estonia (http://prototypely.com)
 * @license MIT Copyright (c) 2017 Prototypely Ltd
 * @link       http://mageflow.com/
 */
class Mageflow_Connect_Model_Handler_Promotion_Rule_Catalog extends Mageflow_Connect_Model_Handler_Abstract
{
    /**
     * @param array $data
     *
     * @return mixed
     */
    public function processData(array $data = array())
    {
        try {

            $data = isset($data[0]) ? $data[0] : $data;
            $message = 'success';

            $savedEntity = $this->processPromotionRuleCatalog($data);

        } catch (Exception $ex) {
            $message = $ex->getMessage();
            $this->log($ex->getMessage());
            $this->log($ex->getTraceAsString());
        }

        return $this->sendProcessingResponse($savedEntity, $message);
    }

    /**
     * @param Mage_Core_Model_Abstract $model
     *
     * @return stdClass
     */
    public function packData(Mage_Core_Model_Abstract $model)
    {
        /**
         * @var Mage_CatalogRule_Model_Rule $model
         */
        $c = $this->packModel($model);

        $c->conditions = $this->processConditions(unserialize($model->getConditionsSerialized()));

        $c->websites = array();
        foreach ($this->findWebsitesByIds($model->getWebsiteIds()) as $website) {
            $c->websites[] = $website->getCode();
        }
        $c->customer_groups = array();
        foreach ($model->getCustomerGroupIds() as $customerGroupId) {
            $c->customer_groups[] = Mage::getModel('customer/group')
                ->load($customerGroupId)->getCustomerGroupCode();
        }
        $c->actions = unserialize($model->getActionsSerialized());

        return $c;
    }

    /**
     * maps id-s to mf_guid-s
     *
     * @param array $conditions
     *
     * @return array
     */
    private function processConditions(Array $conditions)
    {
        if ($conditions['attribute'] == 'attribute_set_id') {
            $targetEntity = Mage::getModel('eav/entity_attribute_set')->load($conditions['value']);
            $conditions['value'] = $targetEntity->getMfGuid();
        }

        if ($conditions['attribute'] == 'category_ids') {
            $categoryIds = explode(', ', $conditions['value']);
            $targetMfGuids = array();
            foreach ($categoryIds as $categoryId) {
                $targetEntity = Mage::getModel('catalog/category')->load($categoryId);
                $targetMfGuids[] = $targetEntity->getMfGuid();
            }
            $conditions['value'] = implode(', ', $targetMfGuids);
        }

        if (isset($conditions['conditions'])) {
            foreach ($conditions['conditions'] as $key => $condition) {
                $conditions['conditions'][$key] = $this->processConditions($condition);
            }

        }

        return $conditions;
    }

    /**
     * create conditions from data array
     *
     * @param array $conditions
     *
     * @return false|Mage_Core_Model_Abstract
     * @throws Exception
     */
    private function createConditions(array $conditions)
    {
        if (is_null($conditions['attribute'])) {
            /**
             * @var Mage_CatalogRule_Model_Rule_Condition_Combine $conditionEntity
             */
            $conditionEntity = Mage::getModel('catalogrule/rule_condition_combine');
        } else {
            $conditionEntity = Mage::getModel('catalogrule/rule_condition_product');
        }

        if ($conditions['attribute'] == 'attribute_set_id') {
            $collection = Mage::getModel('eav/entity_attribute_set')
                ->getCollection()->addFieldToFilter('mf_guid', $conditions['value']);
            $targetEntity = $collection->getFirstItem();
            $conditions['value'] = $targetEntity->getAttributeSetId();
        }

        if ($conditions['attribute'] == 'category_ids') {
            $categoryMfGuids = explode(', ', $conditions['value']);
            $targetIds = array();
            foreach($categoryMfGuids as $categoryMfGuid) {
                $targetEntity =Mage::getModel('catalog/category')->getCollection()->addFieldToFilter(
                    array(
                        array('attribute'=>'mf_guid', 'eq' => $categoryMfGuid)
                    )
                )->getFirstItem();

                if ($targetEntity->getEntityId()) {
                    $targetIds[] = $targetEntity->getEntityId();
                }
            }
            $conditions['value'] = implode(', ', $targetIds);
        }
/*
        $entityData = $conditions;
        unset($entityData['conditions']);
        $conditionEntity->setData($entityData);
        $conditionEntity->save();
*/

        if (isset($conditions['conditions'])) {
            foreach ($conditions['conditions'] as $key => $condition) {
                $conditions['conditions'][$key] = $this->createConditions($condition);
                //$conditionEntity->getConditions()->addCondition($this->createConditions($condition));
            }
        }

      //  $conditionEntity->save();

        return $conditions;
    }

    public function getPreview(Mageflow_Connect_Model_Interfaces_Changeitem $item)
    {
        $out = '';

        $object = json_decode($item->getContent());
        if ($object->name) {
            $out = $object->name;
        }
        return $out;
    }

    public function saveItem($model, $data)
    {
        $model = parent::saveItem($model, $data);
        $model->setConditionsSerialized(serialize($this->createConditions($data['conditions'])));
        $model->save();
        return $model;
    }

    /**
     * @param array $data
     *
     * @return Mage_Core_Model_Abstract|null|object
     */
    protected function processPromotionRuleCatalog(array $data)
    {
        $model = null;
        $savedEntity = null;
        $modelByIdentifier = Mage::getModel('catalogrule/rule')
            ->load($data['name'], 'name');

        $modelByMfGuid = Mage::getModel('catalogrule/rule')
            ->load($data['mf_guid'], 'mf_guid');

        if ($modelByIdentifier->getRuleId()) {
            $model = $modelByIdentifier;
        }
        if ($modelByMfGuid->getRuleId()) {
            $model = $modelByMfGuid;
        }

        if (null === $model) {
            $model = Mage::getModel('catalogrule/rule');
        }

        if ($model->getId() > 0) {
            $data['rule_id'] = $model->getRuleId();
        }

        $data['website_ids'] = $this->getWebsiteIdListByCodes(
            $data['websites']
        );
        $data['customer_group_ids'] = array();
        foreach ($data['customer_groups'] as $customerGroupCode) {
            $data['customer_group_ids'][] = Mage::getModel('customer/group')
                ->load($customerGroupCode, 'customer_group_code')
                ->getCustomerGroupId();
        }

        $savedEntity = $this->saveItem($model, $data);
        return $savedEntity;
    }
}