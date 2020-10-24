<?php

use Komtet\KassaSdk\Exception\SdkException;
use Komtet\KassaSdk\CalculationMethod;
use Komtet\KassaSdk\CalculationSubject;
use Komtet\KassaSdk\Check;
use Komtet\KassaSdk\Client;
use Komtet\KassaSdk\Nomenclature;
use Komtet\KassaSdk\Payment;
use Komtet\KassaSdk\Position;
use Komtet\KassaSdk\TaxSystem;
use Komtet\KassaSdk\QueueManager;
use Komtet\KassaSdk\Vat;
use Bitrix\Main\UserTable;


class KomtetKassa
{
    public static function handleSalePayOrder($id, $val)
    {
        if (gettype($id) == 'object') {
            return;
        }

        if ($val == 'N') {
            return;
        }

        if (!CModule::IncludeModule('sale')) {
            return;
        }

        $ok = new KomtetKassaOld();
        $ok->printCheck($id);
    }

    public static function newHandleSalePayOrder($order)
    {

        if (!gettype($order) == 'object') {
            return;
        }

        $ok = new KomtetKassaD7();
        $ok->printCheck($order);
    }

    public static function newHandleSaleSaveOrder($order)
    {

        if (!gettype($order) == 'object') {
            return;
        }

        $ok = new KomtetKassaD7();
        $ok->printCheck($order);
    }
}


class KomtetKassaBase
{
    protected $manager;
    protected $shouldPrint;
    protected $taxSystem;

    public function __construct()
    {
        $options = $this->getOptions();
        $client = new Client($options['key'], $options['secret']);
        $this->manager = new QueueManager($client);
        $this->manager->registerQueue('default', $options['queue_id']);
        $this->manager->setDefaultQueue('default');
        $this->shouldPrint = $options['should_print'];
        $this->taxSystem = $options['tax_system'];
        $this->paySystems = $options['pay_systems'];
        $this->fullPaymentOrderStatus = $options['full_payment_order_status'];
        $this->prepaymentOrderStatus = $options['prepayment_order_status'];
    }

    private function getOptions()
    {
        $moduleID = 'komtet.kassa';
        $result = array(
            'key' => COption::GetOptionString($moduleID, 'shop_id'),
            'secret' => COption::GetOptionString($moduleID, 'secret_key'),
            'queue_id' => COption::GetOptionString($moduleID, 'queue_id'),
            'should_print' => COption::GetOptionInt($moduleID, 'should_print') == 1,
            'tax_system' => intval(COption::GetOptionInt($moduleID, 'tax_system')),
            'pay_systems' => json_decode(COption::GetOptionString($moduleID, 'pay_systems')),
            'full_payment_order_status' => COption::GetOptionString($moduleID, 'full_payment_order_status'),
            'prepayment_order_status' => COption::GetOptionString($moduleID, 'prepayment_order_status')
        );
        foreach (array('key', 'secret', 'queue_id', 'tax_system', 'full_payment_order_status') as $key) {
            if (empty($result[$key])) {
                error_log(sprintf('Option "%s" for module "komtet.kassa" is required', $key));
            }
        }
        return $result;
    }

    protected function getPaymentProps($orderStatus, $orderExistingStatus, $orderPaid)
    {
        if (!$orderPaid) {
            return array(
                'calculationMethod' => null,
                'calculationSubject' => null,
                'isFullPayment' => null
            );
        }

        // 1 check way
        if (!$this->prepaymentOrderStatus && $orderStatus == $this->fullPaymentOrderStatus) {
            return array(
                'calculationMethod' => CalculationMethod::FULL_PAYMENT,
                'calculationSubject' => CalculationSubject::PRODUCT,
                'isFullPayment' => false
            );
        }
        // 2 checks way
        else if ($this->prepaymentOrderStatus) {
            // prepayment
            if ($orderStatus == $this->prepaymentOrderStatus) {
                return array(
                    'calculationMethod' => CalculationMethod::PRE_PAYMENT_FULL,
                    'calculationSubject' => CalculationSubject::PAYMENT,
                    'isFullPayment' => false
                );
            }
            // full payment
            else if ($orderStatus == $this->fullPaymentOrderStatus &&
                     ($orderExistingStatus == CalculationMethod::PRE_PAYMENT_FULL ||
                      $orderExistingStatus == CalculationMethod::PRE_PAYMENT_FULL.":done")
                ) {
                return array(
                    'calculationMethod' => CalculationMethod::FULL_PAYMENT,
                    'calculationSubject' => CalculationSubject::PRODUCT,
                    'isFullPayment' => true
                );
            }
        }

        return array(
            'calculationMethod' => null,
            'calculationSubject' => null,
            'isFullPayment' => null
        );
    }

    protected function generatePosition($position, $quantity = 1)
    {
        /**
         * Получени позиции заказа
         * @param array $position Позицияв заказе Bitrix
         * @param int|float $quantity Количествово товара в позиции
         */

        $itemVatRate = Vat::RATE_NO;
        if ($this->taxSystem == TaxSystem::COMMON) {
            $itemVatRate = floatval($position->getField('VAT_RATE'));
        }

        return new Position(
            mb_convert_encoding($position->getField('NAME'), 'UTF-8', LANG_CHARSET),
            round($position->getPrice(), 2),
            $quantity,
            round($position->getPrice()*$quantity, 2),
            new Vat($itemVatRate),
            mb_convert_encoding($position->getField('MEASURE_NAME'), 'UTF-8', LANG_CHARSET)
        );
    }

    public function getNomenclatureCodes($position_id)
    {
        /**
         * Получени списка маркировок
         * @param int $position_id Идентификатор позиции в заказе
         */
        global $DB;

        $strSql = "SELECT MARKING_CODE FROM b_sale_store_barcode WHERE MARKING_CODE != '' AND BASKET_ID = " . $position_id;
        $dbRes = $DB->Query($strSql, false);

        $nomenclature_codes = [];
        while ($nomenclature_code = $dbRes->Fetch()) {
            array_push($nomenclature_codes, $nomenclature_code['MARKING_CODE']);
        }
        return $nomenclature_codes;
    }

}


class KomtetKassaOld extends KomtetKassaBase
{
    protected function getPayment($paySystemId, $personTypeId, $sum, $isFullPayment)
    {
        global $DB;
        $strSql = "SELECT * FROM b_sale_pay_system_action WHERE PAY_SYSTEM_ID = $paySystemId";
        $res = $DB->Query($strSql);

        while ($pAction = $res->Fetch()) {
            $arFilter = array(
                'PAY_SYSTEM_ID' => $paySystemId,
                'PERSON_TYPE_ID' => $personTypeId
            );
            $resPaySystemAction = CSalePaySystemAction::GetList(array(), $arFilter);
            while ($pAction = $resPaySystemAction->Fetch()) {
                $arPath = explode('/', $pAction['ACTION_FILE']);
                if (end($arPath) == 'cash') {

                    // если FullPayment, то ставится prepayment - закрытие предоплаты
                    $type = $isFullPayment ? Payment::TYPE_PREPAYMENT : Payment::TYPE_CASH;

                    return new Payment($type, round($sum, 2));
                }
            }
        }

        // если FullPayment, то ставится prepayment - закрытие предоплаты
        $type = $isFullPayment ? Payment::TYPE_PREPAYMENT : Payment::TYPE_CARD;

        return new Payment($type, round($sum, 2));
    }

    public function printCheck($orderID)
    {
        $order = CSaleOrder::GetByID($orderID);

        $paymentProps = $this->getPaymentProps($order['STATUS_ID'], true);
        if ($paymentProps['calculationMethod'] == null) {
            return;
        }

        $user = CUser::GetByID($order['USER_ID'])->Fetch();
        $check = Check::createSell($orderID, $user['EMAIL'], $this->taxSystem);
        $check->setShouldPrint($this->shouldPrint);

        $checkPayment = $this->getPayment(
            $order['PAY_SYSTEM_ID'],
            $order['PERSON_TYPE_ID'],
            floatval($order['PRICE']),
            $paymentProps['isFullPayment']
        );
        $check->addPayment($checkPayment);

        $dbBasket = CSaleBasket::GetList(
            array("ID" => "ASC"),
            array("ORDER_ID" => $order['ID']),
            false,
            false,
            array("NAME", "QUANTITY", "PRICE", "DISCOUNT_PRICE", "VAT_RATE")
        );

        while ($item = $dbBasket->GetNext()) {
            if ($this->taxSystem == TaxSystem::COMMON) {
                $itemVatRate = round(floatval($item['VAT_RATE']) * 100, 2);
            } else {
                $itemVatRate = Vat::RATE_NO;
            }

            $checkPosition = new Position(
                mb_convert_encoding($item['NAME'], 'UTF-8', LANG_CHARSET),
                round($item['PRICE'], 2),
                floatval($item['QUANTITY']),
                round(($item['PRICE'] - $item['DISCOUNT_PRICE']) * $item['QUANTITY'], 2),
                new Vat($itemVatRate)
            );

            if ($item['MEASURE_NAME']) {
                $checkPosition->setMeasureName(mb_convert_encoding($item['MEASURE_NAME'], 'UTF-8', LANG_CHARSET));
            }

            $checkPosition->setCalculationMethod($paymentProps['calculationMethod']);
            $checkPosition->setCalculationSubject($paymentProps['calculationSubject']);

            $check->addPosition($checkPosition);
        }

        $deliveryPrice = round($order['PRICE_DELIVERY'], 2);
        if ($deliveryPrice > 0.0) {
            $delivery = CSaleDelivery::GetByID($order['DELIVERY_ID']);

            $deliveryPosition = new Position(
                mb_convert_encoding($delivery['NAME'], 'UTF-8', LANG_CHARSET),
                $deliveryPrice,
                1,
                $deliveryPrice,
                new Vat(Vat::RATE_NO)
            );

            $deliveryPosition->setCalculationMethod($paymentProps['calculationMethod']);
            $deliveryPosition->setCalculationSubject($paymentProps['calculationSubject']);

            $check->addPosition($deliveryPosition);
        }

        try {
            $this->manager->putCheck($check);
        } catch (SdkException $e) {
            error_log(sprintf('Failed to send check: %s', $e->getMessage()));
        }
    }
}


class KomtetKassaD7 extends KomtetKassaBase
{

    protected function getPayment($payment, $isFullPayment)
    {

        $paySystem = $payment->getPaySystem();

        if ($paySystem->isCash()) {
            // если FullPayment, то ставится prepayment - закрытие предоплаты
            $type = $isFullPayment ? Payment::TYPE_PREPAYMENT : Payment::TYPE_CASH;

            return new Payment($type, round($payment->getSum(), 2));
        }

        // если FullPayment, то ставится prepayment - закрытие предоплаты
        $type = $isFullPayment ? Payment::TYPE_PREPAYMENT : Payment::TYPE_CARD;

        return new Payment($type, round($payment->getSum(), 2));
    }

    public function printCheck($order)
    {

        $existingRow = KomtetKassaReportsTable::getRow(
            array(
                'select' => array('*'),
                'filter' => array('order_id' => $order->getId()),
                'order' => array('id' => 'desc')
            )
        );

        if ($existingRow['state'] == CalculationMethod::FULL_PAYMENT ||
            $existingRow['state'] == CalculationMethod::FULL_PAYMENT.":done" ||
            $existingRow['state'] == "done"
        ) {
            return;
        }

        $paymentProps = $this->getPaymentProps($order->getField('STATUS_ID'), $existingRow['state'], $order->isPaid());

        if ($paymentProps['calculationMethod'] === null) {
            return;
        }

        $propertyCollection = $order->getPropertyCollection();
        $userEmail = $propertyCollection->getUserEmail();
        if ($userEmail) {
            $userEmail = $userEmail->getValue();
        } else { // if email field have not flag "is_email"
            foreach ($propertyCollection as $orderProperty) {
                if ($orderProperty->getField('CODE') == 'EMAIL') {
                    $userEmail = $orderProperty->getValue();
                    break;
                }
            }
        }

        // take email from order user
        if (!$userEmail) {
            $userId = $order->getUserId();
            $rsUser = UserTable::getById($userId);
            $user = $rsUser->fetch();
            $userEmail = $user['EMAIL'];
        }

        $check = Check::createSell($order->getId(), $userEmail, $this->taxSystem);
        $check->setShouldPrint($this->shouldPrint);

        $payments = array();
        $innerBillPayments = array();
        $paymentCollection = $order->getPaymentCollection();
        foreach ($paymentCollection as $payment) {
            if ($payment->isInner()) {
                $innerBillPayments[] = $payment;
                continue;
            } elseif ($this->paySystems and !in_array($payment->getPaymentSystemId(), $this->paySystems)) {
                continue;
            }

            $checkPayment = $this->getPayment($payment, $paymentProps['isFullPayment']);
            $payments[] = $checkPayment;
        }

        if (empty($payments)) {
            return;
        }

        foreach ($payments as $payment) {
            $check->addPayment($payment);
        }

        $positions = $order->getBasket();

        foreach ($positions as $position) {
            if ($position->getField('MARKING_CODE_GROUP')) {
                $positionID = $position->getField('ID');
                $nomenclature_codes = $this->getNomenclatureCodes($positionID);

                if (count($nomenclature_codes) < $position->getQuantity()) {
                    KomtetKassaReportsTable::add([
                        'order_id' => $order->getId(),
                        'state' => $paymentProps['calculationMethod'].":error",
                        'error_description' => "Маркировки заданы не у всех товаров"]
                    );
                    return;
                }

                for ($item = 0; $item < $position->getQuantity(); $item++) {
                    $marked_position = $this->generatePosition($position);
                    $nomenclature_code = array_shift($nomenclature_codes);
                    $marked_position->setNomenclature(new Nomenclature($nomenclature_code));
                    $check->addPosition($marked_position);
                }
            } else {
                $check->addPosition($this->generatePosition($position, $position->getQuantity()));
            }
        }

        foreach ($innerBillPayments as $innerBillPayment) {
            $check->applyDiscount(round($innerBillPayment->getSum(), 2));
        }

        $shipmentCollection = $order->getShipmentCollection();
        foreach ($shipmentCollection as $shipment) {

            if ($shipment->getPrice() > 0.0) {

                if ($this->taxSystem == TaxSystem::COMMON && method_exists($shipment, 'getVatRate')) {
                    $shipmentVatRate = floatval($shipment->getVatRate());
                } else {
                    $shipmentVatRate = Vat::RATE_NO;
                }

                $shipmentPosition = new Position(
                    mb_convert_encoding($shipment->getField('DELIVERY_NAME'), 'UTF-8', LANG_CHARSET),
                    round($shipment->getPrice(), 2),
                    1,
                    round($shipment->getPrice(), 2),
                    new Vat($shipmentVatRate)
                );
                $shipmentPosition->setCalculationMethod($paymentProps['calculationMethod']);
                $shipmentPosition->setCalculationSubject($paymentProps['calculationSubject']);

                $check->addPosition($shipmentPosition);
            }
        }

        try {

            KomtetKassaReportsTable::add([
                'order_id' => $order->getId(),
                'state' => $paymentProps['calculationMethod'],
                'error_description' => '']
            );

            $this->manager->putCheck($check);
        } catch (SdkException $e) {

            KomtetKassaReportsTable::add([
                'order_id' => $order->getId(),
                'state' => $paymentProps['calculationMethod'].":error",
                'error_description' => $e->getMessage()." ".$e->getDescription()]
            );
            error_log(sprintf('Failed to send check: %s', $e->getMessage()));

            return;
        }

        KomtetKassaReportsTable::add([
            'order_id' => $order->getId(),
            'state' => $paymentProps['calculationMethod'].":done",
            'error_description' => '']
        );
    }
}
