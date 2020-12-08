<?php

class BillTechLinksManager
{
	private $batchSize = 100;
	private $verbose = false;
	private $debug = true;
	private $linkShortener;

	public function __construct($verbose = false)
	{
		$this->verbose = $verbose;
		$this->linkShortener = new LinkShortenerApiService();
	}

	/** @return BillTechLink[]
	 * @var string $customerId
	 */
	public function getCustomerPaymentLinks($customerId)
	{
		global $DB;
		$rows = $DB->GetAll("select bpl.*, c.docid
                                from billtech_payment_links bpl
                                         left join cash c on c.id = bpl.src_cash_id
                                         left join billtech_payments bp on bpl.token = bp.token
                                where bp.id is null
                                  and customer_id = ?", array($customerId));
		if (!is_array($rows)) {
			return array();
		}
		return array_map(function ($row) {
			return BillTechLink::fromRow($row);
		}, $rows);
	}

	public function getCashLink($cashId, $params)
	{
		global $DB;
		$row = $DB->GetRow("select l.* from billtech_payment_links l
								left join cash c on l.src_cash_id = c.id
								where src_cash_id = ?", array($cashId));
		if (!$row) {
			return null;
		}
		$link = BillTechLink::fromRow($row);

		$this->addParamsToLink($params, $link);
		return $link;
	}

	public function getBalanceLink($customerId, $params)
	{
		global $DB;
		$row = $DB->GetRow("select l.*, c.docid from billtech_payment_links l
								left join cash c on l.src_cash_id = c.id
								left join billtech_payments bp on l.token = bp.token
								where customer_id = ? and bp.id is null
								order by c.time desc limit 1", array($customerId));
		if (!$row) {
			return null;
		} else {
			$balanceLink = BillTechLink::fromRow($row);
			$this->addParamsToLink(array_merge($params, ['type' => 'balance']), $balanceLink);
			$balanceLink->shortLink .= '/1';
			return $balanceLink;
		}
	}

	public function updateForAll()
	{
		global $DB;
		$actions = array(
			'add' => array(),
			'update' => array(),
			'close' => array(),
		);
		$this->addMissingCustomerInfo();
		$customerIds = $this->getCustomerIdsForUpdate();

		if ($this->verbose) {
			echo "Found " . count($customerIds) . " customers to update\n";
		}

		if (!is_array($customerIds)) {
			return;
		}

		$time = time();

		echo $time . "\n";

		foreach ($customerIds as $idx => $customerId) {
			echo "Collecting actions for customer " . ($idx + 1) . " of " . count($customerIds) . "\n";
			$actions = array_merge_recursive($actions, $this->getCustomerUpdateBalanceActions($customerId));
		}


		if ($this->verbose) {
			echo "Adding " . count($actions['add']) . " links\n";
			echo "Updating " . count($actions['update']) . " links\n";
			echo "Cancelling " . count($actions['close']) . " links\n";
		}

		$this->performActions($actions);
		$this->updateCustomerInfos($customerIds, $time);
	}

	public function updateCustomerBalance($customerId)
	{
		global $DB;
		$DB->BeginTrans();
		if ($this->checkLastUpdate($customerId)) {
			$actions = $this->getCustomerUpdateBalanceActions($customerId);
			$this->performActions($actions);
		}
		$DB->CommitTrans();
	}

	/**
	 * @param $cashItems array
	 * @return BillTechLink[]
	 */
	public function getLiabilities(array $cashItems)
	{
		$balance = array_reduce($cashItems, function ($carry, $item) {
			return $carry + $item['value'];
		}, 0.0);
		$liabilities = array();

		if (!is_array($cashItems)) {
			return array();
		}

		foreach ($cashItems as $cash) {
			$intCashValue = self::moneyToInt($cash['value']);
			$intBalance = self::moneyToInt($balance);
			if ($intCashValue >= 0) {
				continue;
			}
			if ($intBalance < 0) {
				$amountToPay = self::intToMoney(-max(min($intBalance, 0), $intCashValue));
				array_push($liabilities, BillTechLink::linked($cash, $amountToPay));
			}
			$balance = self::intToMoney($intBalance - $intCashValue);
		}
		return $liabilities;
	}

	/* @throws Exception
	 * @var $links BillTechLink[]
	 */
	private function addPayments($links)
	{
		global $DB;

		$linkDataList = array_map(function ($link) {
			return array(
				'cashId' => $link->srcCashId,
				'amount' => $link->amount
			);
		}, $links);

		$generatedLinks = BillTechLinkApiService::generatePaymentLinks($linkDataList);
		$values = array();
		foreach ($generatedLinks as $idx => $generatedLink) {
			$link = $links[$idx];
			array_push($values,
				$link->customerId,
				$link->srcCashId,
				$link->type,
				$generatedLink->link,
				$generatedLink->shortLink,
				$generatedLink->token,
				number_format($link->amount, 2, '.', '')
			);
		}

		$sql = "insert into billtech_payment_links(customer_id, src_cash_id, type, link, short_link, token, amount) values " .
			BillTech::prepareMultiInsertPlaceholders(count($generatedLinks), 7) . ";";
		$DB->Execute($sql, $values);
	}

	/* @throws Exception
	 * @var $link BillTechLink
	 */
	private function updatePaymentAmount(BillTechLink $link)
	{
		global $DB;
		if (self::shouldCancelLink($link)) {
			BillTechLinkApiService::cancelPaymentLink($link->token);
		}
		$linkDataList = array(
			array(
				'cashId' => $link->srcCashId,
				'amount' => $link->amount
			)
		);
		$generatedLink = BillTechLinkApiService::generatePaymentLinks($linkDataList)[0];
		$DB->Execute("update billtech_payment_links set amount = ?, link = ?, short_link = ?, token = ? where id = ?",
			array(number_format($link->amount, 2, '.', ''), $generatedLink->link, $generatedLink->shortLink, $generatedLink->token, $link->id));
	}

	/* @throws Exception
	 * @var $link BillTechLink
	 */
	private function closePayment(BillTechLink $link)
	{
		global $DB;
		if (self::shouldCancelLink($link)) {
			BillTechLinkApiService::cancelPaymentLink($link->token, "PAID");
		}
		$DB->Execute("delete from billtech_payment_links where id = ?", array($link->id));
	}

	private function shouldCancelLink($link)
	{
		global $DB;
		return $DB->GetOne("select count(*) from billtech_payments where token = ?", array($link->token)) == 0;
	}

	public static function moneyToInt($value)
	{
		return intval(round($value * 100));
	}

	public static function intToMoney($value)
	{
		return $value / 100.0;
	}

	/**
	 * @param array $actions
	 * @throws Exception
	 */
	public function performActions($actions)
    {
		global $DB;
        $addBatches = array_chunk($actions['add'], $this->batchSize);
        $errorCount = 0;
        foreach ($addBatches as $idx => $links) {
            if ($this->verbose) {
                echo "Adding batch " . ($idx + 1) . " of " . count($addBatches) . "\n";
            }
            try {
		$DB->BeginTrans();
                $this->addPayments($links);
		$DB->CommitTrans();
            } catch (Exception $e) {
                $errorCount++;
                if ($this->debug) {
                    echo $e->getMessage();
                }
            }
        }

        foreach ($actions['update'] as $idx => $link) {
            if ($this->verbose) {
                echo "Updating link " . ($idx + 1) . " of " . count($actions['update']) . "\n";
            }
            try {
		$DB->BeginTrans();
                $this->updatePaymentAmount($link);
		$DB->CommitTrans();
            } catch (Exception $e) {
                $errorCount++;
                if ($this->debug) {
                    echo $e->getMessage();
                }
            }
        }

        foreach ($actions['close'] as $idx => $link) {
            if ($this->verbose) {
                echo "Closing link " . ($idx + 1) . " of " . count($actions['close']) . "\n";
            }
            try {
		$DB->BeginTrans();
                $this->closePayment($link);
		$DB->CommitTrans();
            } catch (Exception $e) {
                $errorCount++;
                if ($this->debug) {
                    echo $e->getMessage();
                }
            }
        }
    }

	/**
	 * @param $customerId
	 * @return array
	 */
	private function getCustomerUpdateBalanceActions($customerId)
	{
		global $DB;
		$actions = array(
			"add" => array(),
			"update" => array(),
			"close" => array()
		);
		$cashItems = $DB->GetAll("select id, value, customerid from cash where customerid = ? order by time desc, id desc", array($customerId));
		if (!$cashItems) {
			return $actions;
		}

		$liabilities = $this->getLiabilities($cashItems);
		$links = $this->getCustomerPaymentLinks($customerId);
		$paymentMap = BillTech::toMap(function ($payment) {
			/* @var $payment BillTechLink */
			return $payment->srcCashId;
		}, $links);

		foreach ($liabilities as $liability) {
			/* @var $link BillTechLink */
			$link = $paymentMap[$liability->srcCashId];
			if (isset($link) && self::moneyToInt($link->amount) != self::moneyToInt($liability->amount)) {
				$link->amount = $liability->amount;
				array_push($actions['update'], $link);
			} else if (!isset($link) && self::moneyToInt($liability->amount) > 0) {
				array_push($actions['add'], $liability);
			}

			if (isset($link)) {
				unset($paymentMap[$liability->srcCashId]);
			}
		}

		foreach ($paymentMap as $cashId => $link) {
			array_push($actions['close'], $link);
		}

		return $actions;
	}

	private function checkLastUpdate($customerId)
	{
		global $DB;
		$customerInfo = $DB->GetRow("select bci.*, max(c.time) as last_cash_time from billtech_customer_info bci 
										left join cash c on c.customerid = bci.customer_id
										where bci.customer_id = ?
										group by bci.customer_id", array($customerId));

		if ($customerInfo) {
			$DB->Execute("update billtech_customer_info set balance_update_time = ?NOW? where customer_id = ?", array($customerId));
			return $customerInfo['last_cash_time'] > $customerInfo['balance_update_time'];
		} else {
			$DB->Execute("insert into billtech_customer_info (customer_id, balance_update_time) values (?, ?NOW?)", array($customerId));
			return true;
		}
	}

	private function addMissingCustomerInfo()
	{
		global $DB;
		$DB->Execute("insert into billtech_customer_info (customer_id, balance_update_time)
					select cu.id, 0
					from customers cu
							 left join billtech_customer_info bci on bci.customer_id = cu.id
					where bci.customer_id is null;");
	}

	/**
	 * @return array
	 */
	private function getCustomerIdsForUpdate()
	{
		global $DB;
		return $DB->GetCol("select bci.customer_id
										from customers cu
												 left join billtech_customer_info bci on bci.customer_id = cu.id
												 left join cash ca on ca.customerid = cu.id
										group by bci.customer_id, bci.balance_update_time
										having bci.balance_update_time <= coalesce(max(ca.time), 0);");
	}

	/**
	 * @param array $customerIds
	 */
	private function updateCustomerInfos(array $customerIds, $time)
	{
		global $DB;
		$params = $customerIds;
		array_unshift($params, $time);
		$DB->Execute("update billtech_customer_info set balance_update_time = ? where customer_id in (" . BillTech::repeatWithSeparator("?", ",", count($customerIds)) . ")", $params);
	}

	/**
	 * @param array $params
	 * @param BillTechLink $link
	 */
	private function addParamsToLink(array $params, BillTechLink $link)
	{
		$link->link .= http_build_query($params);

		if ($link->shortLink) {
			$link->shortLink = $this->linkShortener->addParameters($link->shortLink, $params);
		}
	}
}
