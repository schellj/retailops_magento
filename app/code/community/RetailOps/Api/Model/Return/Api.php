<?php
/**
The MIT License (MIT)

Copyright (c) 2015 Gud Technologies Incorporated (RetailOps by GüdTech)

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
 */

class RetailOps_Api_Model_Return_Api extends Mage_Sales_Model_Order_Creditmemo_Api
{
    /**
     * Initialize attributes mapping
     */
    public function __construct()
    {
        parent::__construct();
        $this->_ignoredAttributeCodes['creditmemo'] = array('invoice');
    }

    /**
     * Creates Credit Memo
     *
     * @param  mixed $returns
     * @return array
     */
    public function returnPush($returns)
    {
        if (isset($returns['records'])) {
            $returns = $returns['records'];
        }
        $result = array();
        $result['records'] = array();
        foreach ($returns as $return) {
            $returnObj = new Varien_Object($return);
            Mage::dispatchEvent(
                'retailops_return_push_record',
                array('record' => $returnObj)
            );
            $order = Mage::getModel('sales/order')->loadByIncrementId($returnObj->getOrderIncrementId());
            $result['records'][] = $this->create($order, $returnObj->getCreditmemoData(),
                $returnObj->getComment(), $returnObj->getNotifyCustomer(), $returnObj->getIncludeComment(),
                $returnObj->getRefundToStoreCredit());
        }

        return $result;
    }

    /**
     * Create new credit memo for order
     *
     * @param Mage_Sales_Model_Order $order
     * @param array $creditmemoData array('qtys' => array('itemId1' => qty1, ... , 'itemIdN' => qtyN),
     *      'shipping_amount' => value, 'adjustment_positive' => value, 'adjustment_negative' => value)
     * @param string|null $comment
     * @param bool $notifyCustomer
     * @param bool $includeComment
     * @param string $refundToStoreCreditAmount
     * @return string $creditmemoIncrementId
     */
    public function create($order, $creditmemoData = null, $comment = null, $notifyCustomer = false,
                           $includeComment = false, $refundToStoreCreditAmount = null)
    {
        /** @var $helper RetailOps_Api_Helper_Data */
        $helper = Mage::helper('retailops_api');
        try {
            $result = array();
            if (!$order->getId()) {
                $this->_fault('order_not_exists');
            }
            if (!$order->canCreditmemo()) {
                $this->_fault('cannot_create_creditmemo');
            }
            $creditmemoData['order'] = $order;
            $creditmemoData = $this->_prepareCreateData($creditmemoData);

            /** @var $service Mage_Sales_Model_Service_Order */
            $service = Mage::getModel('sales/service_order', $order);
            /** @var $creditmemo Mage_Sales_Model_Order_Creditmemo */
            $creditmemo = $service->prepareCreditmemo($creditmemoData, $order);
            $invoice = $order->getInvoiceCollection()->getFirstItem();
            if ($invoice) {
                $creditmemo->setInvoice($invoice);
            }
            // refund to Store Credit
            if ($refundToStoreCreditAmount) {
                // check if refund to Store Credit is available
                if ($order->getCustomerIsGuest()) {
                    $this->_fault('cannot_refund_to_storecredit');
                }
                $refundToStoreCreditAmount = max(
                    0,
                    min($creditmemo->getBaseCustomerBalanceReturnMax(), $refundToStoreCreditAmount)
                );
                if ($refundToStoreCreditAmount) {
                    $refundToStoreCreditAmount = $creditmemo->getStore()->roundPrice($refundToStoreCreditAmount);
                    $creditmemo->setBaseCustomerBalanceTotalRefunded($refundToStoreCreditAmount);
                    $refundToStoreCreditAmount = $creditmemo->getStore()->roundPrice(
                        $refundToStoreCreditAmount*$order->getStoreToOrderRate()
                    );
                    // this field can be used by customer balance observer
                    $creditmemo->setBsCustomerBalTotalRefunded($refundToStoreCreditAmount);
                    // setting flag to make actual refund to customer balance after credit memo save
                    $creditmemo->setCustomerBalanceRefundFlag(true);
                }
                $creditmemo->setPaymentRefundDisallowed(true);
            }
            $creditmemo->register();
            // add comment to creditmemo
            if (!empty($comment)) {
                $creditmemo->addComment($comment, $notifyCustomer);
            }

            Mage::getModel('core/resource_transaction')
                ->addObject($creditmemo)
                ->addObject($order)
                ->save();
            // send email notification
            $creditmemo->sendEmail($notifyCustomer, ($includeComment ? $comment : ''));
            $result['credit_memo'] = $helper->removeObjectsFromResult($this->_getAttributes($creditmemo, 'creditmemo'));
            $result['items'] = array();
            foreach ($creditmemo->getAllItems() as $item) {
                $result['items'][] = $helper->removeObjectsFromResult($this->_getAttributes($item, 'creditmemo_item'));
            }
            $result['status'] = RetailOps_Api_Helper_Data::API_STATUS_SUCCESS;
        } catch (Mage_Core_Exception $e) {
            $result['status'] = RetailOps_Api_Helper_Data::API_STATUS_FAIL;
            $result['message'] = $e->getMessage();
            $result['code'] = $e->getCode();
        }

        return $result;
    }

    /**
     * Set shipping amount to refund to 0 in case it's not passed
     * Set qtys to refund to 0 by default if they are not passed
     *
     * @param  array $data
     * @return array
     */
    protected function _prepareCreateData($data)
    {
        $data = parent::_prepareCreateData($data);
        $qtys = array();
        if (isset($data['qtys'])) {
            $qtys = $data['qtys'];
        }
        $order = $data['order'];
        $qtysArray = array();
        foreach ($order->getAllItems() as $orderItem) {
            $qtysArray[$orderItem->getId()] = isset($qtys[$orderItem->getId()]) ? $qtys[$orderItem->getId()] : 0;
        }
        $data['qtys'] = $qtysArray;

        if (empty($data['shipping_amount'])) {
            $data['shipping_amount'] = 0;
        }

        return $data;
    }
}
